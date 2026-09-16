<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Message;

use HiEvents\DomainObjects\Enums\MessagePurpose;
use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\Helper\Currency;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\MessagePreviewDTO;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Message\MessageRecipientResolver;
use HiEvents\Services\Domain\Message\SmsMessageBodyBuilder;
use HiEvents\Services\Domain\Sms\SmsTextMeter;
use Illuminate\Config\Repository;

class PreviewMessageHandler
{
    public const CONFIRMATION_THRESHOLD = 100;

    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
        private readonly MessageRecipientResolver $recipientResolver,
        private readonly SmsMessageBodyBuilder $bodyBuilder,
        private readonly SmsTextMeter $meter,
        private readonly Repository $config,
    ) {}

    public function handle(SendMessageDTO $messageData): MessagePreviewDTO
    {
        $event = $this->eventRepository->findById($messageData->event_id);
        $settings = $this->organizerBillingSettingsRepository->findFirstWhere([
            OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $event->getOrganizerId(),
        ]);
        $smsAvailable = (bool) $this->config->get('sms.enabled') && (bool) $settings?->getSmsEnabled();

        $recipients = $this->recipientResolver->resolve($messageData);

        $suffixLength = $messageData->purpose === MessagePurpose::MARKETING ? $this->bodyBuilder->previewSuffixLength() : 0;
        $measured = $this->meter->measure(
            trim((string) $messageData->sms_body).($suffixLength > 0 ? "\n".str_repeat('x', $suffixLength - 1) : ''),
        );

        $feePerMessage = $settings !== null
            ? (float) $settings->getSmsFeePerMessage()
            : (float) $this->config->get('billing.default_sms_fee_per_message');
        $costPerRecipient = Currency::round($measured->parts * $feePerMessage);
        $smsRecipients = $messageData->channel->includesSms() ? $recipients->smsRecipients->count() : 0;
        $emailRecipients = $messageData->channel->includesEmail() ? $recipients->emailRecipients : 0;

        return new MessagePreviewDTO(
            emailRecipients: $emailRecipients,
            smsRecipients: $smsRecipients,
            excludedWithoutConsent: $recipients->excludedWithoutConsent,
            excludedWithoutPhone: $messageData->channel->includesSms() ? $recipients->excludedWithoutPhone : 0,
            smsAvailable: $smsAvailable,
            smsSender: $settings?->getSmsSenderName() ?: (string) $this->config->get('sms.default_sender'),
            smsCharacters: $measured->characters,
            smsEncoding: $measured->encoding,
            smsParts: $measured->parts,
            smsSinglePartLimit: $measured->singlePartLimit,
            smsOptOutSuffixLength: $suffixLength,
            smsCostPerRecipient: $costPerRecipient,
            smsTotalCost: Currency::round($costPerRecipient * $smsRecipients),
            currency: (string) $this->config->get('billing.currency'),
            requiresConfirmation: max($emailRecipients, $smsRecipients) > self::CONFIRMATION_THRESHOLD,
            confirmationWord: $event->getTitle(),
        );
    }
}
