<?php

declare(strict_types=1);

namespace HiEvents\Jobs\Event;

use HiEvents\DomainObjects\Enums\SmsMessageType;
use HiEvents\DomainObjects\Generated\OutgoingMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SmsMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\DomainObjects\Status\SmsMessageStatus;
use HiEvents\Exceptions\Sms\SmsDeliveryException;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\SmsMessagesRepositoryInterface;
use HiEvents\Services\Infrastructure\Sms\ElksSmsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendEventSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var int[]
     */
    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $messageId,
        public readonly int $eventId,
        public readonly int $organizerId,
        public readonly int $orderId,
        public readonly string $recipient,
        public readonly string $sender,
        public readonly string $body,
        public readonly SmsMessageType $type,
    ) {}

    /**
     * @throws SmsDeliveryException
     */
    public function handle(
        ElksSmsClient $client,
        SmsMessagesRepositoryInterface $smsMessagesRepository,
        OutgoingMessageRepositoryInterface $outgoingMessageRepository,
    ): void {
        $existing = $smsMessagesRepository->findFirstWhere([
            SmsMessageDomainObjectAbstract::MESSAGE_ID => $this->messageId,
            SmsMessageDomainObjectAbstract::ORDER_ID => $this->orderId,
        ]);

        if ($existing?->getStatus() === SmsMessageStatus::SENT->name) {
            return;
        }

        try {
            $sent = $client->send($this->recipient, $this->sender, $this->body);
        } catch (SmsDeliveryException $exception) {
            $this->record($smsMessagesRepository, $existing?->getId(), [
                SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::FAILED->name,
                SmsMessageDomainObjectAbstract::ERROR_MESSAGE => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            if ($this->attempts() >= $this->tries) {
                $outgoingMessageRepository->create([
                    OutgoingMessageDomainObjectAbstract::EVENT_ID => $this->eventId,
                    OutgoingMessageDomainObjectAbstract::MESSAGE_ID => $this->messageId,
                    OutgoingMessageDomainObjectAbstract::SUBJECT => 'SMS',
                    OutgoingMessageDomainObjectAbstract::RECIPIENT => $this->recipient,
                    OutgoingMessageDomainObjectAbstract::STATUS => OutgoingMessageStatus::FAILED->name,
                ]);
            }

            throw $exception;
        }

        $this->record($smsMessagesRepository, $existing?->getId(), [
            SmsMessageDomainObjectAbstract::STATUS => SmsMessageStatus::SENT->name,
            SmsMessageDomainObjectAbstract::PARTS => $sent->parts,
            SmsMessageDomainObjectAbstract::PROVIDER_MESSAGE_ID => $sent->providerMessageId ?: null,
            SmsMessageDomainObjectAbstract::ERROR_MESSAGE => null,
            SmsMessageDomainObjectAbstract::SENT_AT => now()->toDateTimeString(),
        ]);

        $outgoingMessageRepository->create([
            OutgoingMessageDomainObjectAbstract::EVENT_ID => $this->eventId,
            OutgoingMessageDomainObjectAbstract::MESSAGE_ID => $this->messageId,
            OutgoingMessageDomainObjectAbstract::SUBJECT => 'SMS',
            OutgoingMessageDomainObjectAbstract::RECIPIENT => $this->recipient,
            OutgoingMessageDomainObjectAbstract::STATUS => OutgoingMessageStatus::SENT->name,
        ]);
    }

    private function record(SmsMessagesRepositoryInterface $repository, ?int $existingId, array $data): void
    {
        if ($existingId !== null) {
            $repository->updateFromArray($existingId, $data);

            return;
        }

        $repository->create($data + [
            SmsMessageDomainObjectAbstract::ORGANIZER_ID => $this->organizerId,
            SmsMessageDomainObjectAbstract::EVENT_ID => $this->eventId,
            SmsMessageDomainObjectAbstract::ORDER_ID => $this->orderId,
            SmsMessageDomainObjectAbstract::MESSAGE_ID => $this->messageId,
            SmsMessageDomainObjectAbstract::TYPE => $this->type->name,
            SmsMessageDomainObjectAbstract::RECIPIENT => $this->recipient,
            SmsMessageDomainObjectAbstract::SENDER => $this->sender,
        ]);
    }
}
