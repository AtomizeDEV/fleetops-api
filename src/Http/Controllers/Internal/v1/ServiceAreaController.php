<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Exports\ServiceAreaExport;
use Fleetbase\Http\Requests\ExportRequest;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ServiceAreaController extends FleetOpsController
{
    /**
     * The resource to query
     *
     * @var string
     */
    public $resource = 'service_area';

    /**
     * Export the fleets to excel or csv
     *
     * @param  \Illuminate\Http\Request  $query
     * @return \Illuminate\Http\Response
     */
    public static function export(ExportRequest $request)
    {
        $format = $request->input('format', 'xlsx');
        $fileName = trim(Str::slug('service-areas-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new ServiceAreaExport(), $fileName);
    }
}
