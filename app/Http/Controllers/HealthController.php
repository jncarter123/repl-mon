<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\HealthReporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/health` — the monitor, monitored.
 *
 * Answers in the shape a Nagios-style plugin wants: a one-line verdict starting
 * `REPLICATION OK` / `WARNING` / `CRITICAL`, the detail beneath it, perfdata
 * after the pipe — and 200 only when everything is healthy, so a check that
 * reads nothing but the status code still catches everything. `?format=json`
 * for anything that would rather have the numbers.
 *
 * Thin on purpose: look up, report, render. The judgement is in HealthReporter.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthReporter $reporter): Response
    {
        $key = $request->string('pair')->trim()->toString();

        $only = null;

        if ($key !== '') {
            $only = $reporter->find($key);

            // A check pointed at a pair that no longer exists is a broken check,
            // and saying so is far better than answering OK about nothing.
            if ($only === null) {
                return $this->respond(
                    $request,
                    "REPLICATION CRITICAL - no pair called `{$key}`\n",
                    ['status' => 'critical', 'summary' => "No pair called `{$key}`."],
                    Response::HTTP_NOT_FOUND,
                );
            }
        }

        $report = $reporter->report($only);

        return $this->respond($request, $report->toText(), $report->toArray(), $report->level->httpStatus());
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function respond(Request $request, string $text, array $json, int $status): Response
    {
        if ($request->query('format') === 'json' || $request->wantsJson()) {
            return response()->json($json, $status);
        }

        return response($text, $status)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
