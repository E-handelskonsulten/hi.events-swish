<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use HiEvents\DomainObjects\Generated\OrganizerBillingSettingDomainObjectAbstract;
use HiEvents\Repository\Interfaces\OrganizerBillingSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;

class SetOrganizerBillingFeesCommand extends Command
{
    protected $signature = 'billing:set-organizer-fees
        {organizerId : The organizer to update}
        {--platform-fee= : Fee per sold ticket in SEK}
        {--sms-fee= : Fee per sent SMS in SEK}';

    protected $description = 'Set the platform and SMS fees used in the monthly billing summary for one organizer';

    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly OrganizerBillingSettingsRepositoryInterface $organizerBillingSettingsRepository,
        private readonly Repository $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organizerId = (int) $this->argument('organizerId');
        $organizer = $this->organizerRepository->findFirstWhere(['id' => $organizerId]);

        if ($organizer === null) {
            $this->error("Organizer {$organizerId} not found.");

            return self::FAILURE;
        }

        $platformFee = $this->parseFee($this->option('platform-fee'));
        $smsFee = $this->parseFee($this->option('sms-fee'));

        if ($platformFee === null && $smsFee === null) {
            $this->error('Pass --platform-fee and/or --sms-fee.');

            return self::FAILURE;
        }

        if ($platformFee === false || $smsFee === false) {
            $this->error('Fees must be non-negative numbers.');

            return self::FAILURE;
        }

        $existing = $this->organizerBillingSettingsRepository->findFirstWhere([
            OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
        ]);

        $data = array_filter([
            OrganizerBillingSettingDomainObjectAbstract::PLATFORM_FEE_PER_TICKET => $platformFee,
            OrganizerBillingSettingDomainObjectAbstract::SMS_FEE_PER_MESSAGE => $smsFee,
        ], static fn ($value) => $value !== null);

        $settings = $existing
            ? $this->organizerBillingSettingsRepository->updateFromArray($existing->getId(), $data)
            : $this->organizerBillingSettingsRepository->create($data + [
                OrganizerBillingSettingDomainObjectAbstract::ORGANIZER_ID => $organizerId,
                OrganizerBillingSettingDomainObjectAbstract::SMS_ENABLED => false,
                OrganizerBillingSettingDomainObjectAbstract::PLATFORM_FEE_PER_TICKET => $this->config->get('billing.default_platform_fee_per_ticket'),
                OrganizerBillingSettingDomainObjectAbstract::SMS_FEE_PER_MESSAGE => $this->config->get('billing.default_sms_fee_per_message'),
            ]);

        $this->info(sprintf(
            '%s: platform fee %.2f SEK per ticket, SMS fee %.2f SEK per message.',
            $organizer->getName(),
            $settings->getPlatformFeePerTicket(),
            $settings->getSmsFeePerMessage(),
        ));

        return self::SUCCESS;
    }

    private function parseFee(?string $value): float|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (! is_numeric($normalized) || (float) $normalized < 0) {
            return false;
        }

        return round((float) $normalized, 2);
    }
}
