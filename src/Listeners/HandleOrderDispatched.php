<?php

namespace Fleetbase\FleetOps\Listeners;

use Illuminate\Support\Carbon;
use Fleetbase\FleetOps\Events\OrderDispatched;
use Fleetbase\FleetOps\Events\OrderDispatchFailed;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Notifications\OrderDispatched as OrderDispatchedNotification;
use Fleetbase\FleetOps\Notifications\OrderPing;
use Fleetbase\FleetOps\Support\Flow;
use Fleetbase\FleetOps\Support\Utils;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Queue\InteractsWithQueue;

class HandleOrderDispatched
{
    // use InteractsWithQueue;

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(OrderDispatched $event)
    {
        /** @var \Fleetbase\FleetOps\Models\Order $order */
        $order = $event->getModelRecord();

        // set company session
        session([
            'company' => $order->company_uuid
        ]);

        // Log::info('[HandleOrderDispatched] ' . print_r($order, true));

        /** make sure driver is assigned if not trigger failed dispatch */
        if (!$order->hasDriverAssigned && !$order->adhoc) {
            return event(new OrderDispatchFailed($order, 'No driver assigned for order to dispatch to.'));
        }

        /** check for dispatch code in activity options, if there is the correct dispatch code update activity */
        $activity = Flow::getDispatchActivity($order);

        if ($activity) {
            /** update order activity */
            $location = $order->getLastLocation();

            $order->setStatus($activity['code']);
            $order->createActivity($activity['status'], $activity['details'], $location, $activity['code']);
        }

        /** update dispatch attributes */
        $order->dispatched = true;
        $order->dispatched_at = Carbon::now();
        $order->save();
        $order->flushAttributesCache();

        /** if order is adhoc ping drivers within radius of pickup to accept order **/
        if ($order->adhoc) {
            $order->load(['company']);

            $pickup = $order->getPickupLocation();
            $distance = Utils::get($order, 'adhoc_distance') ?? Utils::get($order, 'company.options.fleetops.adhoc_distance', 6000); // defaults to 6km

            if (!Utils::isPoint($pickup)) {
                return;
            }

            $drivers = Driver::where(['status' => 'active', 'online' => 1])
                ->whereHas('company', function ($q) {
                    $q->whereHas('users', function ($q) {
                        $q->whereHas('driver', function ($q) {
                            $q->where(['status' => 'active', 'online' => 1]);
                            $q->whereNull('deleted_at');
                        });
                    });
                })
                ->whereNull('deleted_at')
                ->distanceSphere('location', $pickup, $distance)
                ->distanceSphereValue('location', $pickup)
                ->withoutGlobalScopes()
                ->get();

            return $drivers->each(function ($driver) use ($order) {
                try {
                    $driver->notify(new OrderPing($order, $driver->distance));
                } catch (\Exception $exception) {
                    // failed to notify driver for order dispatch for reason uknown -- exit silently
                }
            });
        }

        /** @var \Fleetbase\Models\Driver */
        $driver = Driver::where('uuid', $order->driver_assigned_uuid)->withoutGlobalScopes()->first();

        /** notify driver order has dispatched */
        if (!$driver) {
            return event(new OrderDispatchFailed($order, 'Order was dispatched, but driver was unable to be notified.'));
        }

        $driver->notify(new OrderDispatchedNotification($order));
    }
}
