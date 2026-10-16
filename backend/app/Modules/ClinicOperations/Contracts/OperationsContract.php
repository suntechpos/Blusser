<?php

namespace App\Modules\ClinicOperations\Contracts;

/** Planning contract only: does not register routes, grant permissions or authorize requests. */
final class OperationsContract
{
    public const VERSION = 'sprint-3-stage-1';

    public static function permissions(): array
    {
        $catalog = [];
        foreach ([
            'patients' => ['view', 'update'],
            'appointments' => ['view', 'update', 'cancel', 'check_in', 'no_show'],
            'visits' => ['view'],
            'queues' => ['view', 'call', 'recall', 'start', 'complete', 'skip', 'cancel', 'requeue'],
        ] as $section => $actions) {
            foreach ($actions as $action) {
                $catalog["$section.$action"] = ['own', 'assigned_branches', 'entire_clinic'];
            }
        }
        foreach (['patients.create', 'patients.link_branch', 'appointments.create', 'visits.create', 'queues.create', 'reception.view', 'doctors.view', 'rooms.view', 'schedules.view'] as $code) {
            $catalog[$code] = ['assigned_branches', 'entire_clinic'];
        }
        foreach (['patients.archive', 'patients.restore', 'patients.export', 'appointments.export', 'doctors.create', 'doctors.update', 'rooms.create', 'rooms.update', 'schedules.create', 'schedules.update'] as $code) {
            $catalog[$code] = ['entire_clinic'];
        }

        return $catalog;
    }

    /** Source states by action; target state. Guards and related writes remain future work. */
    public static function transitions(): array
    {
        return [
            'appointment' => [
                'reschedule' => [['scheduled'], 'scheduled'],
                'check_in' => [['scheduled'], 'checked_in'],
                'cancel' => [['scheduled'], 'cancelled'],
                'no_show' => [['scheduled'], 'no_show'],
                'queue_cancel' => [['checked_in'], 'cancelled'],
                'start' => [['checked_in'], 'in_progress'],
                'complete' => [['in_progress'], 'completed'],
            ],
            'visit' => [
                'enqueue' => [['arrived'], 'queued'],
                'call' => [['queued'], 'called'],
                'recall' => [['called'], 'called'],
                'start' => [['called'], 'in_progress'],
                'complete' => [['in_progress'], 'completed'],
                'skip' => [['queued', 'called'], 'skipped'],
                'requeue' => [['skipped'], 'queued'],
                'cancel' => [['arrived', 'queued', 'called', 'skipped'], 'cancelled'],
            ],
            'queue_ticket' => [
                'call' => [['waiting'], 'called'],
                'recall' => [['called'], 'called'],
                'start' => [['called'], 'in_progress'],
                'complete' => [['in_progress'], 'completed'],
                'skip' => [['waiting', 'called'], 'skipped'],
                'requeue' => [['skipped'], 'waiting'],
                'cancel' => [['waiting', 'called', 'skipped'], 'cancelled'],
            ],
        ];
    }
}
