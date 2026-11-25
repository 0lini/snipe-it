<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\Warranty\WarrantyLookupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class LookupWarranties extends Command
{
    protected $signature = 'snipeit:lookup-warranties
        {--asset= : Only look up this asset id}
        {--force : Ignore the recheck interval}
        {--dry-run : Query the vendors but store nothing}
        {--limit= : Stop after this many assets}';

    protected $description = 'Look up warranty / support coverage for assets from their serial numbers and store it.';

    public function handle(WarrantyLookupService $service): int
    {
        if (! config('warranty.enabled') && ! $this->option('asset')) {
            $this->info('Warranty lookup is disabled (WARRANTY_LOOKUP_ENABLED).');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit') > 0 ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) config('warranty.apply_to_asset');
        $recheckBefore = now()->subDays((int) config('warranty.recheck_days'));

        $query = Asset::query()
            ->with('model.manufacturer')
            ->whereNotNull('serial')
            ->where('serial', '!=', '');

        if ($this->option('asset')) {
            $query->whereKey($this->option('asset'));
        } elseif (! $this->option('force')) {
            $query->where(function ($q) use ($recheckBefore) {
                $q->whereNull('warranty_last_checked_at')->orWhere('warranty_last_checked_at', '<', $recheckBefore);
            });
        }

        $looked = 0;
        $found = 0;
        $errors = 0;

        foreach ($query->orderBy('id')->lazyById(100) as $asset) {
            if ($limit !== null && $looked >= $limit) {
                break;
            }

            if ($service->providerFor($asset) === null) {
                continue;
            }

            $looked++;

            try {
                if ($dryRun) {
                    $coverages = $service->providerFor($asset)->lookup(trim($asset->serial));
                } else {
                    $coverages = $service->refresh($asset, $apply) ?? [];
                }

                $found += count($coverages) > 0 ? 1 : 0;
                $this->line(sprintf('#%d %s: %d coverage plan(s)', $asset->id, $asset->serial, count($coverages)));
            } catch (Throwable $e) {
                $errors++;
                Log::warning("Warranty lookup failed for asset {$asset->id}: {$e->getMessage()}");
                $this->warn("#{$asset->id} {$asset->serial}: {$e->getMessage()}");
            }
        }

        $this->info("Looked up {$looked} asset(s); coverage found for {$found}; {$errors} error(s).");

        return $errors > 0 && $found === 0 && $looked > 0 ? self::FAILURE : self::SUCCESS;
    }
}
