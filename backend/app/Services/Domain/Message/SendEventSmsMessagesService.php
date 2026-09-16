<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Message;

use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\Jobs\Event\SendEventSmsJob;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\MessageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Message\DTO\SmsRecipientDTO;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;

class SendEventSmsMessagesService
{
    public const RECIPIENTS_PER_MINUTE = 60;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
        private readonly MessageRepositoryInterface $messageRepository,
        private readonly MessageRecipientResolver $recipientResolver,
        private readonly SmsMessageBodyBuilder $bodyBuilder,
        private readonly Dispatcher $dispatcher,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(SendMessageDTO $messageData): void
    {
        if ($messageData->is_test || $messageData->id === null || $messageData->sms_body === null) {
            return;
        }

        $event = $this->eventRepository->findById($messageData->event_id);
        $settings = $this->organizerBillingSettingsRepository->findFirstWhere([
            OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
        ]);

        if (! $this->config->get('sms.enabled') || $settings === null || ! $settings->getSmsEnabled()) {
            $this->logger->warning('SMS message skipped: SMS delivery is not enabled for the organizer', [
                'message_id' => $messageData->id,
                'organizer_id' => $event->getOrganizerId(),
            ]);
            $this->messageRepository->updateFromArray($messageData->id, ['status' => MessageStatus::FAILED->name]);

            return;
        }

        $recipients = $this->recipientResolver->resolve($messageData)->smsRecipients;
        $sender = $settings->getSmsSenderName() ?: $this->config->get('sms.default_sender');
        $type = SmsMessageType::forPurpose($messageData->purpose);

        $recipients->each(function (SmsRecipientDTO $recipient, int $index) use ($messageData, $event, $sender, $type) {
            $this->dispatcher->dispatch((new SendEventSmsJob(
                messageId: $messageData->id,
                eventId: $event->getId(),
                organizerId: $event->getOrganizerId(),
                orderId: $recipient->orderId,
                recipient: $recipient->phone,
                sender: $sender,
                body: $this->bodyBuilder->build($messageData->sms_body, $messageData->purpose, $recipient->orderId),
                type: $type,
            ))->delay(now()->addMinutes(intdiv($index, self::RECIPIENTS_PER_MINUTE))));
        });

        $this->logger->info('SMS message queued', [
            'message_id' => $messageData->id,
            'event_id' => $event->getId(),
            'purpose' => $messageData->purpose->name,
            'recipients' => $recipients->count(),
        ]);

        $this->messageRepository->updateFromArray($messageData->id, ['status' => MessageStatus::SENT->name]);
    }
}
