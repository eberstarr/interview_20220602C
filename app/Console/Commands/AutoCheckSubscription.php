<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

use App\Modules\CountryAssignment\Repositories\CountryAssignmentRepository;
use App\Modules\SubscriptionAssignment\Repositories\SubscriptionAssignmentRepository;
use App\Modules\Account\Repositories\AccountRepository;
use App\Modules\Subscription\Repositories\SubscriptionRepository;

class AutoCheckSubscription extends Command
{
    protected $signature = 'hestia:check_subscription';

    protected $description = '排程-每月 確認subscription 到期狀況';

    public function __construct(
        CountryAssignmentRepository $CountryAssignmentmodel,
        SubscriptionAssignmentRepository $SubscriptionAssignmentmodel,
        AccountRepository $Accountmodel,
        SubscriptionRepository $Subscriptionmodel
    ) {
        $this->CountryAssignmentmodel = $CountryAssignmentmodel;
        $this->SubscriptionAssignmentmodel = $SubscriptionAssignmentmodel;
        $this->Accountmodel = $Accountmodel;
        $this->Subscriptionmodel = $Subscriptionmodel;

        parent::__construct();
    }

    public function handle()
    {
        $now = now();
        $date_zone = $this->option('date_zone');

        Log::channel('monthly_command_log')
            ->info('Run check subscription start.');

        /* 取得符合 時區的Country Assign */
        $CountryAssignment = $this->CountryAssignmentmodel->where('active', 1)
            ->whereHas('country', function ($q) use ($date_zone) {
                $q->where('date_zone', $date_zone)
                    ->where('active', 1);
            });

        /* 取得符合 時區的Subscription Assignment */
        foreach($CountryAssignment as $item){
            $SubscriptionAssignment = array_merge($SubscriptionAssignment, $this->SubscriptionAssignmentmodel->where('active', 1)
                ->whereHas('country', function ($q) use ($date_zone) {
                    $q->where('country_assignment_id', $item['id'])
                        ->where('active', 1);
                })
            );
        }

        foreach($SubscriptionAssignment as $item){
            $subscription_created_time = $item['subscription']['created_at'];
            $subscription_type = $item['subscription']['subscription_type'];
            $subscription_unit = $item['subscription']['subscription_unit'];

            /* 判斷機制: 訂閱時間 < 目前時間-(n 月or 年) */
            if($subscription_created_time < strtotime("-".$subscription_unit." ".$subscription_type)){
                $account_id = $item['account_id'];
                $subscription_id = $item['subscription']['id'];
                $country_assignment_id = $item['country_assignment_id'];

                $this->Accountmodel->where('id', $account_id)->update(
                    ['status' => 'false']
                );
                $this->Subscriptionmodel->where('id', $subscription_id)->update(
                    ['active' => 0]
                );
                $this->CountryAssignmentmodel->where('id', $account_id)->update(
                    ['active' => 0]
                );
            }
        }

        Log::channel('monthly_command_log')
            ->info('Run check subscription end.');
    }
}
