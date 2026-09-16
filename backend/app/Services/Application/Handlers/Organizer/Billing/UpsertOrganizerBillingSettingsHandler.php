<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Organizer\Billing;

use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\DomainObjects\OrganizerBillingSettingDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Jobs\Sms\RescheduleTicketSmsJob;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Application\Handlers\Organizer\Billing\DTO\UpsertOrganizerBillingSettingsDTO;
use Illuminate\Config\Repository;
use Psr\Log\LoggerInterface;

class UpsertOrganizerBillingSettingsHandler
{
    public function __construct(
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException
     */
    public function handle(UpsertOrganizerBillingSettingsDTO $dto): OrganizerBillingSettingDomainObject
    {
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $dto->organizerId,
            'account_id' => $dto->accountId,
        ]);

        if ($organizer === null) {
            throw new ResourceNotFoundException(__('Organizer not found.'));
        }

        $senderName = $dto->smsSenderName !== null && trim($dto->smsSenderName) !== '' ? trim($dto->smsSenderName) : null;

        $data = [
            OrganizerBillingSettingDomainObjectAbstract::SMS_ENABLED => $dto->smsEnabled,
            OrganizerBillingSettingDomainObjectAbstract::SMS_SENDER_NAME => $senderName,
            OrganizerBillingSettingDomainObjectAbstract::SMS_LEAD_HOURS => $dto->smsLeadHours,
        ];

        $existing = $this->organizerBillingSettingsRepository->findFirstWhere([
            OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
        ]);

        $settings = $existing
            ? $this->organizerBillingSettingsRepository->updateFromArray($existing->getId(), $data)
            : $this->organizerBillingSettingsRepository->create($data + [
                OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $dto->organizerId,
                OrganizerBillingSettingDomainObjectAbstract::PLATFORM_FEE_PER_TICKET => $this->config->get('billing.default_platform_fee_per_ticket'),
                OrganizerBillingSettingDomainObjectAbstract::SMS_FEE_PER_MESSAGE => $this->config->get('billing.default_sms_fee_per_message'),
            ]);

        $previousLeadHours = $existing?->getSmsLeadHours() === null ? null : (int) $existing->getSmsLeadHours();

        if ($existing !== null && $previousLeadHours !== $dto->smsLeadHours) {
            dispatch(RescheduleTicketSmsJob::forOrganizer($dto->organizerId));
        }

        $this->logger->info('Organizer SMS settings saved', [
            'organizer_id' => $dto->organizerId,
            'sms_enabled' => $dto->smsEnabled,
            'sms_sender_name' => $senderName,
            'sms_lead_hours' => $dto->smsLeadHours,
        ]);

        return $settings;
    }
}
