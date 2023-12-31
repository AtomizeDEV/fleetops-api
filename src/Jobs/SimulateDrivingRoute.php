<?php

namespace Fleetbase\FleetOps\Jobs;

use Fleetbase\FleetOps\Events\DriverSimulatedLocationChanged;
use Fleetbase\FleetOps\Models\Driver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

/**
 * Class SimulateDrivingRoute
 * Simulates a driving route for a given driver by dispatching events at each waypoint.
 */
class SimulateDrivingRoute implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var Driver The driver for whom the route is being simulated.
     */
    public Driver $driver;

    /**
     * @var array The waypoints that make up the route.
     */
    public array $waypoints = [];

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 60 * 15;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 20;

    /**
     * Create a new job instance.
     *
     * @param \Fleetbase\FleetOps\Models\Driver $driver The driver for whom the route is being simulated.
     * @param array $waypoints The waypoints that make up the route.
     */
    public function __construct(Driver $driver, array $waypoints = [])
    {
        $this->driver = $driver->withoutRelations();
        $this->waypoints = $waypoints;
    }

    /**
     * Execute the job.
     * Dispatches an event for each waypoint, simulating the driver's movement along the route.
     */
    public function handle(): void
    {
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', 0);

        $firstWaypoint = reset($this->waypoints);
        $remainingWaypoints = array_slice($this->waypoints, 1, null, true);

        SimulateWaypointReached::withChain(
            Arr::map(
                $remainingWaypoints,
                function ($waypoint, $index) {
                    return new SimulateWaypointReached($this->driver, $waypoint, ['index' => $index]);
                }
            )
        )->dispatch($this->driver, $firstWaypoint, ['index' => 0]);
    }
}
