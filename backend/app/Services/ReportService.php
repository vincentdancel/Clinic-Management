<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReportService
{
    public const CATALOG = [
        'patient-registrations' => [
            'title' => 'Patient Registration Report',
            'description' => 'New patient registrations, demographics, and contact completeness.',
            'category' => 'Patients & Clinical',
            'permissions' => ['patients.view'],
        ],
        'appointments' => [
            'title' => 'Appointment Report',
            'description' => 'Scheduled, completed, cancelled, and no-show appointment trends.',
            'category' => 'Patients & Clinical',
            'permissions' => ['appointments.view'],
        ],
        'consultations' => [
            'title' => 'Consultation Report',
            'description' => 'Clinical consultations, diagnoses, and follow-up tracking.',
            'category' => 'Patients & Clinical',
            'permissions' => ['clinical.view'],
        ],
        'doctor-activity' => [
            'title' => 'Doctor Activity Report',
            'description' => 'Physician workload across appointments, consultations, and prescriptions.',
            'category' => 'Staff & Operations',
            'permissions' => ['clinical.view', 'appointments.view'],
        ],
        'employees' => [
            'title' => 'Employee Report',
            'description' => 'Clinic workforce roster, departments, categories, and employment status.',
            'category' => 'Staff & Operations',
            'permissions' => ['employees.view'],
        ],
        'daily-revenue' => [
            'title' => 'Daily Revenue Report',
            'description' => 'Day-by-day collected payment revenue broken down by payment method.',
            'category' => 'Financial & Billing',
            'permissions' => ['billing.view'],
        ],
        'monthly-revenue' => [
            'title' => 'Monthly Revenue Report',
            'description' => 'Monthly invoiced totals vs. collected revenue and collection rates.',
            'category' => 'Financial & Billing',
            'permissions' => ['billing.view'],
        ],
        'payments' => [
            'title' => 'Payment Report',
            'description' => 'Detailed payment transactions, receipts, methods, and statuses.',
            'category' => 'Financial & Billing',
            'permissions' => ['billing.view'],
        ],
        'unpaid-invoices' => [
            'title' => 'Unpaid Invoice Report',
            'description' => 'Outstanding and partially paid invoices with aging and balances due.',
            'category' => 'Financial & Billing',
            'permissions' => ['billing.view'],
        ],
        'medicine-inventory' => [
            'title' => 'Medicine Inventory Report',
            'description' => 'Active medicine stock levels, valuation, and reorder readiness.',
            'category' => 'Pharmacy & Inventory',
            'permissions' => ['inventory.manage'],
        ],
        'low-stock' => [
            'title' => 'Low Stock Report',
            'description' => 'Medicines at or below reorder threshold requiring replenishment.',
            'category' => 'Pharmacy & Inventory',
            'permissions' => ['inventory.manage'],
        ],
        'expired-medicines' => [
            'title' => 'Expired Medicine Report',
            'description' => 'Expired and soon-to-expire medicine batches with remaining stock loss.',
            'category' => 'Pharmacy & Inventory',
            'permissions' => ['inventory.manage'],
        ],
    ];

    public function canAccessReport(User $user, string $type): bool
    {
        if (! isset(self::CATALOG[$type]) || ! $user->hasPermission('reports.view')) {
            return false;
        }

        if ($user->hasRole('administrator')) {
            return true;
        }

        foreach (self::CATALOG[$type]['permissions'] as $requiredPermission) {
            if ($user->hasPermission($requiredPermission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allowedCatalog(User $user): array
    {
        $items = [];
        foreach (self::CATALOG as $type => $meta) {
            if ($this->canAccessReport($user, $type)) {
                $items[] = [
                    'type' => $type,
                    'title' => $meta['title'],
                    'description' => $meta['description'],
                    'category' => $meta['category'],
                ];
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(User $user): array
    {
        $doctors = [];
        if ($user->hasRole('administrator') || $user->hasPermission('appointments.view') || $user->hasPermission('clinical.view')) {
            $doctors = Employee::query()
                ->where(function ($query): void {
                    $query->where('employee_category', 'doctor')
                        ->orWhereHas('doctorSpecializations');
                })
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'employee_number'])
                ->map(fn (Employee $emp): array => [
                    'id' => $emp->id,
                    'name' => trim("{$emp->first_name} {$emp->last_name}"),
                    'employee_number' => $emp->employee_number,
                ])
                ->values()
                ->all();
        }

        $departments = [];
        if ($user->hasRole('administrator') || $user->hasPermission('employees.view') || $user->hasPermission('clinical.view')) {
            $departments = Department::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->toArray();
        }

        $medicineCategories = [];
        if ($user->hasRole('administrator') || $user->hasPermission('inventory.manage')) {
            $medicineCategories = MedicineCategory::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray();
        }

        return [
            'catalog' => $this->allowedCatalog($user),
            'can_export' => $user->hasPermission('reports.export'),
            'doctors' => $doctors,
            'departments' => $departments,
            'medicine_categories' => $medicineCategories,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(string $type, array $filters = []): array
    {
        $meta = self::CATALOG[$type] ?? throw new \InvalidArgumentException("Unsupported report type [{$type}].");
        $rawPeriod = $filters['period'] ?? 'monthly';
        $period = in_array($rawPeriod, ['daily', 'weekly', 'monthly', 'yearly'], true)
            ? (string) $rawPeriod
            : 'monthly';
        $search = trim((string) ($filters['search'] ?? ''));

        [$dateFrom, $dateTo] = $this->resolveDateRange($filters);

        $payload = match ($type) {
            'patient-registrations' => $this->patientRegistrations($dateFrom, $dateTo, $period, $search, $filters),
            'appointments' => $this->appointments($dateFrom, $dateTo, $period, $search, $filters),
            'consultations' => $this->consultations($dateFrom, $dateTo, $period, $search, $filters),
            'doctor-activity' => $this->doctorActivity($dateFrom, $dateTo, $search, $filters),
            'employees' => $this->employees($dateFrom, $dateTo, $search, $filters),
            'daily-revenue' => $this->dailyRevenue($dateFrom, $dateTo, $search, $filters),
            'monthly-revenue' => $this->monthlyRevenue($dateFrom, $dateTo, $filters),
            'payments' => $this->payments($dateFrom, $dateTo, $period, $search, $filters),
            'unpaid-invoices' => $this->unpaidInvoices($dateFrom, $dateTo, $search, $filters),
            'medicine-inventory' => $this->medicineInventory($search, $filters),
            'low-stock' => $this->lowStock($search, $filters),
            'expired-medicines' => $this->expiredMedicines($dateFrom, $dateTo, $search, $filters),
        };

        return [
            'type' => $type,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'category' => $meta['category'],
            'filters' => [
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'period' => $period,
                'search' => $search,
                'status' => $filters['status'] ?? null,
                'doctor_id' => isset($filters['doctor_id']) ? (int) $filters['doctor_id'] : null,
                'department_id' => isset($filters['department_id']) ? (int) $filters['department_id'] : null,
                'method' => $filters['method'] ?? null,
                'category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
                'gender' => $filters['gender'] ?? null,
                'expiry_scope' => $filters['expiry_scope'] ?? null,
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            ...$payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function resolveDateRange(array $filters): array
    {
        $from = ! empty($filters['date_from']) ? CarbonImmutable::parse((string) $filters['date_from'])->startOfDay() : null;
        $to = ! empty($filters['date_to']) ? CarbonImmutable::parse((string) $filters['date_to'])->endOfDay() : null;

        if ($from && $to && $from->greaterThan($to)) {
            return [$to->startOfDay(), $from->endOfDay()];
        }

        return [$from, $to];
    }

    private function patientRegistrations(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $period, string $search, array $filters): array
    {
        $query = Patient::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo);
        }
        if (! empty($filters['gender'])) {
            $query->where('gender', $filters['gender']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('patient_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $patients = $query->get();

        $male = $patients->where('gender', 'male')->count();
        $female = $patients->where('gender', 'female')->count();
        $other = $patients->count() - $male - $female;

        $trend = $this->groupTrend($patients, fn (Patient $p) => $p->created_at?->toIso8601String(), $period);

        return [
            'summary' => [
                ['key' => 'total', 'label' => 'Registered Patients', 'value' => $patients->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'male', 'label' => 'Male', 'value' => $male, 'format' => 'number', 'tone' => 'teal'],
                ['key' => 'female', 'label' => 'Female', 'value' => $female, 'format' => 'number', 'tone' => 'rose'],
                ['key' => 'with_contact', 'label' => 'With Phone/Email', 'value' => $patients->filter(fn (Patient $p) => ! empty($p->phone) || ! empty($p->email))->count(), 'format' => 'number', 'tone' => 'green'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Registrations Over Time',
                    'type' => 'bar',
                    'labels' => $trend['labels'],
                    'series' => [['name' => 'Patients', 'data' => $trend['counts']]],
                ],
                'breakdown' => [
                    'title' => 'Gender Distribution',
                    'type' => 'donut',
                    'items' => array_values(array_filter([
                        ['label' => 'Male', 'value' => $male],
                        ['label' => 'Female', 'value' => $female],
                        ['label' => 'Other / Unspecified', 'value' => $other],
                    ], fn (array $item) => $item['value'] > 0)),
                ],
            ],
            'columns' => [
                ['key' => 'patient_number', 'label' => 'Patient No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'full_name', 'label' => 'Patient Name', 'align' => 'left', 'format' => 'text'],
                ['key' => 'gender', 'label' => 'Gender', 'align' => 'left', 'format' => 'text'],
                ['key' => 'date_of_birth', 'label' => 'Birth Date', 'align' => 'left', 'format' => 'date'],
                ['key' => 'phone', 'label' => 'Phone', 'align' => 'left', 'format' => 'text'],
                ['key' => 'email', 'label' => 'Email', 'align' => 'left', 'format' => 'text'],
                ['key' => 'created_at', 'label' => 'Registered On', 'align' => 'left', 'format' => 'datetime'],
            ],
            'rows' => $patients->map(fn (Patient $p): array => [
                'id' => $p->id,
                'patient_number' => $p->patient_number,
                'full_name' => trim("{$p->first_name} {$p->last_name}"),
                'gender' => $p->gender ? ucfirst($p->gender) : 'Unspecified',
                'date_of_birth' => $p->date_of_birth?->toDateString() ?? '—',
                'phone' => $p->phone ?? '—',
                'email' => $p->email ?? '—',
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function appointments(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $period, string $search, array $filters): array
    {
        $query = Appointment::query()
            ->with(['patient:id,patient_number,first_name,last_name', 'doctor:id,first_name,last_name'])
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($dateFrom) {
            $query->where('scheduled_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('scheduled_at', '<=', $dateTo);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['doctor_id'])) {
            $query->where('doctor_id', (int) $filters['doctor_id']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('appointment_number', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($pq) => $pq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('patient_number', 'like', "%{$search}%"))
                    ->orWhereHas('doctor', fn ($dq) => $dq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $appointments = $query->get();
        $total = $appointments->count();
        $completed = $appointments->where('status', Appointment::STATUS_COMPLETED)->count();
        $cancelledOrNoShow = $appointments->whereIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])->count();
        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        $trend = $this->groupTrend($appointments, fn (Appointment $a) => $a->scheduled_at?->toIso8601String(), $period);
        $statusBreakdown = collect(Appointment::STATUSES)->map(fn (string $status): array => [
            'label' => ucwords(str_replace('_', ' ', $status)),
            'value' => $appointments->where('status', $status)->count(),
        ])->filter(fn (array $item) => $item['value'] > 0)->values()->all();

        return [
            'summary' => [
                ['key' => 'total', 'label' => 'Total Appointments', 'value' => $total, 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'completed', 'label' => 'Completed', 'value' => $completed, 'format' => 'number', 'tone' => 'green'],
                ['key' => 'cancelled', 'label' => 'Cancelled / No-Show', 'value' => $cancelledOrNoShow, 'format' => 'number', 'tone' => 'rose'],
                ['key' => 'completion_rate', 'label' => 'Completion Rate', 'value' => $completionRate, 'format' => 'percent', 'tone' => 'teal'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Appointments Over Time',
                    'type' => 'bar',
                    'labels' => $trend['labels'],
                    'series' => [['name' => 'Appointments', 'data' => $trend['counts']]],
                ],
                'breakdown' => [
                    'title' => 'Status Distribution',
                    'type' => 'donut',
                    'items' => $statusBreakdown,
                ],
            ],
            'columns' => [
                ['key' => 'appointment_number', 'label' => 'Appt. No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'scheduled_at', 'label' => 'Scheduled At', 'align' => 'left', 'format' => 'datetime'],
                ['key' => 'patient_name', 'label' => 'Patient', 'align' => 'left', 'format' => 'text'],
                ['key' => 'doctor_name', 'label' => 'Doctor', 'align' => 'left', 'format' => 'text'],
                ['key' => 'duration_minutes', 'label' => 'Duration (m)', 'align' => 'right', 'format' => 'number'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
                ['key' => 'reason', 'label' => 'Reason', 'align' => 'left', 'format' => 'text'],
            ],
            'rows' => $appointments->map(fn (Appointment $a): array => [
                'id' => $a->id,
                'appointment_number' => $a->appointment_number ?? "APT-{$a->id}",
                'scheduled_at' => $a->scheduled_at?->toIso8601String(),
                'patient_name' => $a->patient ? trim("{$a->patient->first_name} {$a->patient->last_name}") : '—',
                'doctor_name' => $a->doctor ? 'Dr. '.trim("{$a->doctor->first_name} {$a->doctor->last_name}") : '—',
                'duration_minutes' => $a->duration_minutes,
                'status' => $a->status,
                'reason' => $a->reason ?? '—',
            ])->values()->all(),
        ];
    }

    private function consultations(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $period, string $search, array $filters): array
    {
        $query = Consultation::query()
            ->with(['patient:id,patient_number,first_name,last_name', 'doctor:id,first_name,last_name'])
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        if ($dateFrom) {
            $query->where('started_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('started_at', '<=', $dateTo);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['doctor_id'])) {
            $query->where('doctor_id', (int) $filters['doctor_id']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('consultation_number', 'like', "%{$search}%")
                    ->orWhere('chief_complaint', 'like', "%{$search}%")
                    ->orWhere('diagnosis', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($pq) => $pq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('doctor', fn ($dq) => $dq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $consultations = $query->get();
        $completed = $consultations->where('status', Consultation::STATUS_COMPLETED)->count();
        $inProgress = $consultations->where('status', Consultation::STATUS_IN_PROGRESS)->count();
        $withFollowUp = $consultations->filter(fn (Consultation $c) => $c->follow_up_date !== null)->count();

        $trend = $this->groupTrend($consultations, fn (Consultation $c) => ($c->started_at ?? $c->created_at)?->toIso8601String(), $period);
        $statusBreakdown = collect(Consultation::STATUSES)->map(fn (string $status): array => [
            'label' => ucwords(str_replace('_', ' ', $status)),
            'value' => $consultations->where('status', $status)->count(),
        ])->filter(fn (array $item) => $item['value'] > 0)->values()->all();

        return [
            'summary' => [
                ['key' => 'total', 'label' => 'Total Consultations', 'value' => $consultations->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'completed', 'label' => 'Completed', 'value' => $completed, 'format' => 'number', 'tone' => 'green'],
                ['key' => 'in_progress', 'label' => 'In Progress', 'value' => $inProgress, 'format' => 'number', 'tone' => 'amber'],
                ['key' => 'follow_ups', 'label' => 'With Follow-up', 'value' => $withFollowUp, 'format' => 'number', 'tone' => 'teal'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Consultations Over Time',
                    'type' => 'bar',
                    'labels' => $trend['labels'],
                    'series' => [['name' => 'Consultations', 'data' => $trend['counts']]],
                ],
                'breakdown' => [
                    'title' => 'Consultation Statuses',
                    'type' => 'donut',
                    'items' => $statusBreakdown,
                ],
            ],
            'columns' => [
                ['key' => 'consultation_number', 'label' => 'Consult. No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'started_at', 'label' => 'Started At', 'align' => 'left', 'format' => 'datetime'],
                ['key' => 'patient_name', 'label' => 'Patient', 'align' => 'left', 'format' => 'text'],
                ['key' => 'doctor_name', 'label' => 'Doctor', 'align' => 'left', 'format' => 'text'],
                ['key' => 'chief_complaint', 'label' => 'Chief Complaint', 'align' => 'left', 'format' => 'text'],
                ['key' => 'diagnosis', 'label' => 'Diagnosis', 'align' => 'left', 'format' => 'text'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
                ['key' => 'follow_up_date', 'label' => 'Follow-up', 'align' => 'left', 'format' => 'date'],
            ],
            'rows' => $consultations->map(fn (Consultation $c): array => [
                'id' => $c->id,
                'consultation_number' => $c->consultation_number ?? "CON-{$c->id}",
                'started_at' => ($c->started_at ?? $c->created_at)?->toIso8601String(),
                'patient_name' => $c->patient ? trim("{$c->patient->first_name} {$c->patient->last_name}") : '—',
                'doctor_name' => $c->doctor ? 'Dr. '.trim("{$c->doctor->first_name} {$c->doctor->last_name}") : '—',
                'chief_complaint' => $c->chief_complaint ?? '—',
                'diagnosis' => $c->diagnosis ?? '—',
                'status' => $c->status,
                'follow_up_date' => $c->follow_up_date?->toDateString() ?? '—',
            ])->values()->all(),
        ];
    }

    private function doctorActivity(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $search, array $filters): array
    {
        $query = Employee::query()
            ->with(['department:id,name', 'doctorSpecializations:id,name'])
            ->where(function ($q): void {
                $q->where('employee_category', 'doctor')
                    ->orWhereHas('doctorSpecializations')
                    ->orWhereHas('doctorAppointments')
                    ->orWhereHas('consultations');
            });

        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('employee_number', 'like', "%{$search}%");
            });
        }

        $doctors = $query->withCount([
            'doctorAppointments as total_appointments' => function ($q) use ($dateFrom, $dateTo): void {
                if ($dateFrom) {
                    $q->where('scheduled_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('scheduled_at', '<=', $dateTo);
                }
            },
            'doctorAppointments as completed_appointments' => function ($q) use ($dateFrom, $dateTo): void {
                $q->where('status', Appointment::STATUS_COMPLETED);
                if ($dateFrom) {
                    $q->where('scheduled_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('scheduled_at', '<=', $dateTo);
                }
            },
            'consultations as total_consultations' => function ($q) use ($dateFrom, $dateTo): void {
                if ($dateFrom) {
                    $q->where('started_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('started_at', '<=', $dateTo);
                }
            },
            'consultations as completed_consultations' => function ($q) use ($dateFrom, $dateTo): void {
                $q->where('status', Consultation::STATUS_COMPLETED);
                if ($dateFrom) {
                    $q->where('started_at', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('started_at', '<=', $dateTo);
                }
            },
            'prescriptions as prescriptions_issued' => function ($q) use ($dateFrom, $dateTo): void {
                if ($dateFrom) {
                    $q->whereDate('prescribed_at', '>=', $dateFrom->toDateString());
                }
                if ($dateTo) {
                    $q->whereDate('prescribed_at', '<=', $dateTo->toDateString());
                }
            },
        ])->orderByDesc('completed_consultations')->orderBy('first_name')->get();

        $topDoctors = $doctors->take(8);

        return [
            'summary' => [
                ['key' => 'doctors', 'label' => 'Doctors Tracked', 'value' => $doctors->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'appointments', 'label' => 'Total Appointments', 'value' => (int) $doctors->sum('total_appointments'), 'format' => 'number', 'tone' => 'teal'],
                ['key' => 'consultations', 'label' => 'Completed Consultations', 'value' => (int) $doctors->sum('completed_consultations'), 'format' => 'number', 'tone' => 'green'],
                ['key' => 'prescriptions', 'label' => 'Prescriptions Issued', 'value' => (int) $doctors->sum('prescriptions_issued'), 'format' => 'number', 'tone' => 'violet'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Doctor Workload Comparison',
                    'type' => 'bar',
                    'labels' => $topDoctors->map(fn (Employee $d) => 'Dr. '.$d->last_name)->values()->all(),
                    'series' => [
                        ['name' => 'Appointments', 'data' => $topDoctors->pluck('total_appointments')->map(fn ($v) => (int) $v)->values()->all()],
                        ['name' => 'Completed Consultations', 'data' => $topDoctors->pluck('completed_consultations')->map(fn ($v) => (int) $v)->values()->all()],
                    ],
                ],
                'breakdown' => [
                    'title' => 'Completed Consultations by Doctor',
                    'type' => 'donut',
                    'items' => $topDoctors->filter(fn (Employee $d) => (int) $d->completed_consultations > 0)
                        ->map(fn (Employee $d): array => [
                            'label' => 'Dr. '.trim("{$d->first_name} {$d->last_name}"),
                            'value' => (int) $d->completed_consultations,
                        ])->values()->all(),
                ],
            ],
            'columns' => [
                ['key' => 'employee_number', 'label' => 'Employee No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'doctor_name', 'label' => 'Doctor', 'align' => 'left', 'format' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'format' => 'text'],
                ['key' => 'specializations', 'label' => 'Specializations', 'align' => 'left', 'format' => 'text'],
                ['key' => 'total_appointments', 'label' => 'Appointments', 'align' => 'right', 'format' => 'number'],
                ['key' => 'completed_appointments', 'label' => 'Appt. Done', 'align' => 'right', 'format' => 'number'],
                ['key' => 'total_consultations', 'label' => 'Consultations', 'align' => 'right', 'format' => 'number'],
                ['key' => 'completed_consultations', 'label' => 'Consult. Done', 'align' => 'right', 'format' => 'number'],
                ['key' => 'prescriptions_issued', 'label' => 'Prescriptions', 'align' => 'right', 'format' => 'number'],
            ],
            'rows' => $doctors->map(fn (Employee $d): array => [
                'id' => $d->id,
                'employee_number' => $d->employee_number,
                'doctor_name' => 'Dr. '.trim("{$d->first_name} {$d->last_name}"),
                'department' => $d->department?->name ?? '—',
                'specializations' => $d->doctorSpecializations->pluck('name')->join(', ') ?: 'General Practice',
                'total_appointments' => (int) $d->total_appointments,
                'completed_appointments' => (int) $d->completed_appointments,
                'total_consultations' => (int) $d->total_consultations,
                'completed_consultations' => (int) $d->completed_consultations,
                'prescriptions_issued' => (int) $d->prescriptions_issued,
            ])->values()->all(),
        ];
    }

    private function employees(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $search, array $filters): array
    {
        $query = Employee::query()->with('department:id,name')->orderBy('first_name')->orderBy('last_name');

        if ($dateFrom) {
            $query->whereDate('hire_date', '>=', $dateFrom->toDateString());
        }
        if ($dateTo) {
            $query->whereDate('hire_date', '<=', $dateTo->toDateString());
        }
        if (! empty($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('employment_status', $filters['status']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('employee_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('job_title', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $employees = $query->get();
        $active = $employees->where('employment_status', 'active')->count();
        $doctors = $employees->where('employee_category', 'doctor')->count();
        $byDepartment = $employees->groupBy(fn (Employee $e) => $e->department?->name ?? 'Unassigned')
            ->map(fn (Collection $group, string $dept): array => ['label' => $dept, 'value' => $group->count()])
            ->values();

        return [
            'summary' => [
                ['key' => 'total', 'label' => 'Total Employees', 'value' => $employees->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'active', 'label' => 'Active Staff', 'value' => $active, 'format' => 'number', 'tone' => 'green'],
                ['key' => 'inactive', 'label' => 'Inactive / On Leave', 'value' => $employees->count() - $active, 'format' => 'number', 'tone' => 'amber'],
                ['key' => 'doctors', 'label' => 'Doctors', 'value' => $doctors, 'format' => 'number', 'tone' => 'teal'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Headcount by Department',
                    'type' => 'bar',
                    'labels' => $byDepartment->pluck('label')->all(),
                    'series' => [['name' => 'Employees', 'data' => $byDepartment->pluck('value')->all()]],
                ],
                'breakdown' => [
                    'title' => 'Employment Status',
                    'type' => 'donut',
                    'items' => $employees->groupBy('employment_status')
                        ->map(fn (Collection $g, string $status): array => ['label' => ucwords(str_replace('_', ' ', $status)), 'value' => $g->count()])
                        ->values()->all(),
                ],
            ],
            'columns' => [
                ['key' => 'employee_number', 'label' => 'Employee No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'full_name', 'label' => 'Name', 'align' => 'left', 'format' => 'text'],
                ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'format' => 'text'],
                ['key' => 'employee_category', 'label' => 'Category', 'align' => 'left', 'format' => 'text'],
                ['key' => 'job_title', 'label' => 'Job Title', 'align' => 'left', 'format' => 'text'],
                ['key' => 'employment_status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
                ['key' => 'hire_date', 'label' => 'Hire Date', 'align' => 'left', 'format' => 'date'],
                ['key' => 'phone', 'label' => 'Phone', 'align' => 'left', 'format' => 'text'],
            ],
            'rows' => $employees->map(fn (Employee $e): array => [
                'id' => $e->id,
                'employee_number' => $e->employee_number,
                'full_name' => trim("{$e->first_name} {$e->last_name}"),
                'department' => $e->department?->name ?? '—',
                'employee_category' => ucfirst((string) ($e->employee_category ?? 'staff')),
                'job_title' => $e->job_title,
                'employment_status' => $e->employment_status,
                'hire_date' => $e->hire_date?->toDateString() ?? '—',
                'phone' => $e->phone ?? '—',
            ])->values()->all(),
        ];
    }

    private function dailyRevenue(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $search, array $filters): array
    {
        $from = $dateFrom ?? CarbonImmutable::today()->subDays(29)->startOfDay();
        $to = $dateTo ?? CarbonImmutable::today()->endOfDay();

        $query = Payment::query()
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereBetween('paid_at', [$from, $to])
            ->orderBy('paid_at');

        if (! empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        $payments = $query->get();

        $grouped = $payments->groupBy(fn (Payment $p) => $p->paid_at->toDateString())
            ->map(function (Collection $dayPayments, string $date): array {
                return [
                    'date' => $date,
                    'transactions_count' => $dayPayments->count(),
                    'cash_amount' => round((float) $dayPayments->where('method', Payment::METHOD_CASH)->sum('amount'), 2),
                    'card_amount' => round((float) $dayPayments->where('method', Payment::METHOD_CARD)->sum('amount'), 2),
                    'bank_transfer_amount' => round((float) $dayPayments->where('method', Payment::METHOD_BANK_TRANSFER)->sum('amount'), 2),
                    'other_amount' => round((float) $dayPayments->where('method', Payment::METHOD_OTHER)->sum('amount'), 2),
                    'total_revenue' => round((float) $dayPayments->sum('amount'), 2),
                ];
            })
            ->sortByDesc('date')
            ->values();

        $totalRevenue = round((float) $payments->sum('amount'), 2);
        $daysWithRevenue = max($grouped->count(), 1);
        $peakDay = $grouped->sortByDesc('total_revenue')->first();
        $chronological = $grouped->sortBy('date')->values();

        return [
            'summary' => [
                ['key' => 'total_revenue', 'label' => 'Total Revenue', 'value' => $totalRevenue, 'format' => 'currency', 'tone' => 'teal'],
                ['key' => 'transactions', 'label' => 'Completed Payments', 'value' => $payments->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'avg_daily', 'label' => 'Avg. Revenue / Active Day', 'value' => round($totalRevenue / $daysWithRevenue, 2), 'format' => 'currency', 'tone' => 'green'],
                ['key' => 'peak_day', 'label' => 'Highest Daily Total', 'value' => (float) ($peakDay['total_revenue'] ?? 0), 'format' => 'currency', 'tone' => 'amber'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Daily Revenue Trend',
                    'type' => 'line',
                    'labels' => $chronological->pluck('date')->all(),
                    'series' => [['name' => 'Revenue', 'data' => $chronological->pluck('total_revenue')->all()]],
                ],
                'breakdown' => [
                    'title' => 'Revenue by Payment Method',
                    'type' => 'donut',
                    'items' => collect(Payment::METHODS)->map(fn (string $m): array => [
                        'label' => ucwords(str_replace('_', ' ', $m)),
                        'value' => round((float) $payments->where('method', $m)->sum('amount'), 2),
                    ])->filter(fn (array $i) => $i['value'] > 0)->values()->all(),
                ],
            ],
            'columns' => [
                ['key' => 'date', 'label' => 'Date', 'align' => 'left', 'format' => 'date'],
                ['key' => 'transactions_count', 'label' => 'Transactions', 'align' => 'right', 'format' => 'number'],
                ['key' => 'cash_amount', 'label' => 'Cash', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'card_amount', 'label' => 'Card', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'bank_transfer_amount', 'label' => 'Bank Transfer', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'other_amount', 'label' => 'Other', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'total_revenue', 'label' => 'Total Revenue', 'align' => 'right', 'format' => 'currency'],
            ],
            'rows' => $grouped->all(),
        ];
    }

    private function monthlyRevenue(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, array $filters): array
    {
        $from = $dateFrom ?? CarbonImmutable::today()->startOfMonth()->subMonths(11);
        $to = $dateTo ?? CarbonImmutable::today()->endOfDay();

        $paymentsQuery = Payment::query()
            ->where('status', Payment::STATUS_COMPLETED)
            ->whereBetween('paid_at', [$from, $to]);

        if (! empty($filters['method'])) {
            $paymentsQuery->where('method', $filters['method']);
        }

        $payments = $paymentsQuery->get();
        $invoices = Invoice::query()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereDate('issued_at', '>=', $from->toDateString())
            ->whereDate('issued_at', '<=', $to->toDateString())
            ->get();

        $paymentsByMonth = $payments->groupBy(fn (Payment $p) => $p->paid_at->format('Y-m'));
        $invoicesByMonth = $invoices->groupBy(fn (Invoice $i) => $i->issued_at->format('Y-m'));

        $months = $paymentsByMonth->keys()->merge($invoicesByMonth->keys())->unique()->sort()->values();
        if ($months->isEmpty()) {
            $months = collect([$to->format('Y-m')]);
        }

        $rows = $months->map(function (string $ym) use ($paymentsByMonth, $invoicesByMonth): array {
            $monthPayments = $paymentsByMonth->get($ym, collect());
            $monthInvoices = $invoicesByMonth->get($ym, collect());
            $invoiced = round((float) $monthInvoices->sum('total_amount'), 2);
            $collected = round((float) $monthPayments->sum('amount'), 2);

            return [
                'month' => $ym,
                'label' => CarbonImmutable::createFromFormat('Y-m', $ym)->format('M Y'),
                'invoices_count' => $monthInvoices->count(),
                'invoiced_amount' => $invoiced,
                'payments_count' => $monthPayments->count(),
                'collected_revenue' => $collected,
                'collection_rate' => $invoiced > 0 ? round(($collected / $invoiced) * 100, 1) : ($collected > 0 ? 100.0 : 0.0),
            ];
        });

        $totalInvoiced = round((float) $rows->sum('invoiced_amount'), 2);
        $totalCollected = round((float) $rows->sum('collected_revenue'), 2);

        return [
            'summary' => [
                ['key' => 'collected', 'label' => 'Collected Revenue', 'value' => $totalCollected, 'format' => 'currency', 'tone' => 'teal'],
                ['key' => 'invoiced', 'label' => 'Total Invoiced', 'value' => $totalInvoiced, 'format' => 'currency', 'tone' => 'blue'],
                ['key' => 'collection_rate', 'label' => 'Collection Rate', 'value' => $totalInvoiced > 0 ? round(($totalCollected / $totalInvoiced) * 100, 1) : 0, 'format' => 'percent', 'tone' => 'green'],
                ['key' => 'avg_monthly', 'label' => 'Avg. Monthly Revenue', 'value' => round($totalCollected / max($rows->count(), 1), 2), 'format' => 'currency', 'tone' => 'violet'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Monthly Invoiced vs. Collected Revenue',
                    'type' => 'bar',
                    'labels' => $rows->pluck('label')->all(),
                    'series' => [
                        ['name' => 'Invoiced', 'data' => $rows->pluck('invoiced_amount')->all()],
                        ['name' => 'Collected', 'data' => $rows->pluck('collected_revenue')->all()],
                    ],
                ],
                'breakdown' => [
                    'title' => 'Collected Revenue by Method',
                    'type' => 'donut',
                    'items' => collect(Payment::METHODS)->map(fn (string $m): array => [
                        'label' => ucwords(str_replace('_', ' ', $m)),
                        'value' => round((float) $payments->where('method', $m)->sum('amount'), 2),
                    ])->filter(fn (array $i) => $i['value'] > 0)->values()->all(),
                ],
            ],
            'columns' => [
                ['key' => 'label', 'label' => 'Month', 'align' => 'left', 'format' => 'text'],
                ['key' => 'invoices_count', 'label' => 'Invoices', 'align' => 'right', 'format' => 'number'],
                ['key' => 'invoiced_amount', 'label' => 'Invoiced', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'payments_count', 'label' => 'Payments', 'align' => 'right', 'format' => 'number'],
                ['key' => 'collected_revenue', 'label' => 'Collected', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'collection_rate', 'label' => 'Collection %', 'align' => 'right', 'format' => 'percent'],
            ],
            'rows' => $rows->sortByDesc('month')->values()->all(),
        ];
    }

    private function payments(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $period, string $search, array $filters): array
    {
        $query = Payment::query()
            ->with(['invoice:id,invoice_number,patient_id', 'invoice.patient:id,first_name,last_name', 'receiver:id,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id');

        if ($dateFrom) {
            $query->where('paid_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('paid_at', '<=', $dateTo);
        }
        if (! empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('patient', fn ($pq) => $pq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")));
            });
        }

        $payments = $query->get();
        $completed = $payments->where('status', Payment::STATUS_COMPLETED);
        $trend = $this->groupTrend($completed, fn (Payment $p) => $p->paid_at?->toIso8601String(), $period, fn (Payment $p) => (float) $p->amount);

        return [
            'summary' => [
                ['key' => 'total_amount', 'label' => 'Completed Payments', 'value' => round((float) $completed->sum('amount'), 2), 'format' => 'currency', 'tone' => 'teal'],
                ['key' => 'count', 'label' => 'Transactions', 'value' => $payments->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'cash', 'label' => 'Cash Total', 'value' => round((float) $completed->where('method', Payment::METHOD_CASH)->sum('amount'), 2), 'format' => 'currency', 'tone' => 'green'],
                ['key' => 'non_cash', 'label' => 'Card / Bank / Other', 'value' => round((float) $completed->where('method', '!=', Payment::METHOD_CASH)->sum('amount'), 2), 'format' => 'currency', 'tone' => 'violet'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Payment Volume Over Time',
                    'type' => 'bar',
                    'labels' => $trend['labels'],
                    'series' => [['name' => 'Amount Collected', 'data' => $trend['sums']]],
                ],
                'breakdown' => [
                    'title' => 'By Payment Method',
                    'type' => 'donut',
                    'items' => collect(Payment::METHODS)->map(fn (string $m): array => [
                        'label' => ucwords(str_replace('_', ' ', $m)),
                        'value' => round((float) $completed->where('method', $m)->sum('amount'), 2),
                    ])->filter(fn (array $i) => $i['value'] > 0)->values()->all(),
                ],
            ],
            'columns' => [
                ['key' => 'receipt_number', 'label' => 'Receipt No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'paid_at', 'label' => 'Paid At', 'align' => 'left', 'format' => 'datetime'],
                ['key' => 'invoice_number', 'label' => 'Invoice No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'patient_name', 'label' => 'Patient', 'align' => 'left', 'format' => 'text'],
                ['key' => 'method', 'label' => 'Method', 'align' => 'left', 'format' => 'text'],
                ['key' => 'amount', 'label' => 'Amount', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
                ['key' => 'received_by', 'label' => 'Received By', 'align' => 'left', 'format' => 'text'],
            ],
            'rows' => $payments->map(fn (Payment $p): array => [
                'id' => $p->id,
                'receipt_number' => $p->receipt_number ?? "RCP-{$p->id}",
                'paid_at' => $p->paid_at?->toIso8601String(),
                'invoice_number' => $p->invoice?->invoice_number ?? '—',
                'patient_name' => $p->invoice?->patient ? trim("{$p->invoice->patient->first_name} {$p->invoice->patient->last_name}") : '—',
                'method' => ucwords(str_replace('_', ' ', $p->method)),
                'amount' => round((float) $p->amount, 2),
                'status' => $p->status,
                'received_by' => $p->receiver?->name ?? '—',
            ])->values()->all(),
        ];
    }

    private function unpaidInvoices(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $search, array $filters): array
    {
        $today = CarbonImmutable::today();
        $query = Invoice::query()
            ->with(['patient:id,patient_number,first_name,last_name', 'payments' => fn ($q) => $q->where('status', Payment::STATUS_COMPLETED)])
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID])
            ->orderBy('due_at')
            ->orderByDesc('issued_at');

        if ($dateFrom) {
            $query->whereDate('issued_at', '>=', $dateFrom->toDateString());
        }
        if ($dateTo) {
            $query->whereDate('issued_at', '<=', $dateTo->toDateString());
        }
        if (! empty($filters['status']) && in_array($filters['status'], [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID], true)) {
            $query->where('status', $filters['status']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('patient', fn ($pq) => $pq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('patient_number', 'like', "%{$search}%"));
            });
        }

        $invoices = $query->get()->map(function (Invoice $inv) use ($today): array {
            $paid = round((float) $inv->payments->sum('amount'), 2);
            $total = round((float) $inv->total_amount, 2);
            $balance = max(round($total - $paid, 2), 0.0);
            $daysOverdue = ($inv->due_at && $inv->due_at->lt($today)) ? (int) $inv->due_at->diffInDays($today) : 0;

            return [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'issued_at' => $inv->issued_at?->toDateString(),
                'due_at' => $inv->due_at?->toDateString() ?? '—',
                'patient_name' => $inv->patient ? trim("{$inv->patient->first_name} {$inv->patient->last_name}") : '—',
                'total_amount' => $total,
                'paid_amount' => $paid,
                'remaining_balance' => $balance,
                'status' => $inv->status,
                'days_overdue' => $daysOverdue,
            ];
        });

        $unpaidBalance = round((float) $invoices->where('status', Invoice::STATUS_UNPAID)->sum('remaining_balance'), 2);
        $partialBalance = round((float) $invoices->where('status', Invoice::STATUS_PARTIALLY_PAID)->sum('remaining_balance'), 2);

        return [
            'summary' => [
                ['key' => 'count', 'label' => 'Open Invoices', 'value' => $invoices->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'total_billed', 'label' => 'Total Billed', 'value' => round((float) $invoices->sum('total_amount'), 2), 'format' => 'currency', 'tone' => 'teal'],
                ['key' => 'outstanding', 'label' => 'Outstanding Balance', 'value' => round((float) $invoices->sum('remaining_balance'), 2), 'format' => 'currency', 'tone' => 'rose'],
                ['key' => 'overdue', 'label' => 'Overdue Invoices', 'value' => $invoices->where('days_overdue', '>', 0)->count(), 'format' => 'number', 'tone' => 'amber'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Top Outstanding Balances',
                    'type' => 'bar',
                    'labels' => $invoices->sortByDesc('remaining_balance')->take(8)->pluck('invoice_number')->values()->all(),
                    'series' => [['name' => 'Balance Due', 'data' => $invoices->sortByDesc('remaining_balance')->take(8)->pluck('remaining_balance')->values()->all()]],
                ],
                'breakdown' => [
                    'title' => 'Balance by Invoice Status',
                    'type' => 'donut',
                    'items' => array_values(array_filter([
                        ['label' => 'Unpaid', 'value' => $unpaidBalance],
                        ['label' => 'Partially Paid', 'value' => $partialBalance],
                    ], fn (array $i) => $i['value'] > 0)),
                ],
            ],
            'columns' => [
                ['key' => 'invoice_number', 'label' => 'Invoice No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'issued_at', 'label' => 'Issued Date', 'align' => 'left', 'format' => 'date'],
                ['key' => 'due_at', 'label' => 'Due Date', 'align' => 'left', 'format' => 'date'],
                ['key' => 'patient_name', 'label' => 'Patient', 'align' => 'left', 'format' => 'text'],
                ['key' => 'total_amount', 'label' => 'Total', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'paid_amount', 'label' => 'Paid', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'remaining_balance', 'label' => 'Balance Due', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
                ['key' => 'days_overdue', 'label' => 'Days Overdue', 'align' => 'right', 'format' => 'number'],
            ],
            'rows' => $invoices->values()->all(),
        ];
    }

    private function medicineInventory(string $search, array $filters): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $query = Medicine::query()
            ->with('category:id,name')
            ->withSum(['batches as valid_stock' => function ($q) use ($today): void {
                $q->where(function ($sub) use ($today): void {
                    $sub->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today);
                });
            }], 'quantity_remaining')
            ->orderBy('name');

        if (! empty($filters['category_id'])) {
            $query->where('medicine_category_id', (int) $filters['category_id']);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('generic_name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        $rows = $query->get()->map(function (Medicine $m): array {
            $stock = round((float) ($m->valid_stock ?? 0), 2);
            $reorder = round((float) $m->reorder_level, 2);
            $unitCost = round((float) $m->unit_cost, 2);
            $stockValue = round($stock * $unitCost, 2);
            $status = $stock <= 0 ? 'out_of_stock' : ($stock <= $reorder ? 'low_stock' : 'in_stock');

            return [
                'id' => $m->id,
                'sku' => $m->sku,
                'name' => $m->name,
                'generic_name' => $m->generic_name ?? '—',
                'category' => $m->category?->name ?? 'Uncategorized',
                'unit' => $m->unit,
                'current_stock' => $stock,
                'reorder_level' => $reorder,
                'unit_cost' => $unitCost,
                'selling_price' => round((float) $m->selling_price, 2),
                'stock_value' => $stockValue,
                'stock_status' => $status,
            ];
        });

        $byCategory = $rows->groupBy('category')->map(fn (Collection $g, string $cat): array => [
            'label' => $cat,
            'value' => round((float) $g->sum('stock_value'), 2),
        ])->values();

        return [
            'summary' => [
                ['key' => 'total_items', 'label' => 'Total Medicines', 'value' => $rows->count(), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'total_units', 'label' => 'Total Stock Units', 'value' => round((float) $rows->sum('current_stock'), 2), 'format' => 'number', 'tone' => 'teal'],
                ['key' => 'total_value', 'label' => 'Inventory Cost Value', 'value' => round((float) $rows->sum('stock_value'), 2), 'format' => 'currency', 'tone' => 'green'],
                ['key' => 'low_or_out', 'label' => 'Low / Out of Stock', 'value' => $rows->whereIn('stock_status', ['low_stock', 'out_of_stock'])->count(), 'format' => 'number', 'tone' => 'amber'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Stock Value by Category',
                    'type' => 'bar',
                    'labels' => $byCategory->pluck('label')->all(),
                    'series' => [['name' => 'Stock Value', 'data' => $byCategory->pluck('value')->all()]],
                ],
                'breakdown' => [
                    'title' => 'Stock Health',
                    'type' => 'donut',
                    'items' => array_values(array_filter([
                        ['label' => 'In Stock', 'value' => $rows->where('stock_status', 'in_stock')->count()],
                        ['label' => 'Low Stock', 'value' => $rows->where('stock_status', 'low_stock')->count()],
                        ['label' => 'Out of Stock', 'value' => $rows->where('stock_status', 'out_of_stock')->count()],
                    ], fn (array $i) => $i['value'] > 0)),
                ],
            ],
            'columns' => [
                ['key' => 'sku', 'label' => 'SKU', 'align' => 'left', 'format' => 'text'],
                ['key' => 'name', 'label' => 'Medicine', 'align' => 'left', 'format' => 'text'],
                ['key' => 'category', 'label' => 'Category', 'align' => 'left', 'format' => 'text'],
                ['key' => 'unit', 'label' => 'Unit', 'align' => 'left', 'format' => 'text'],
                ['key' => 'current_stock', 'label' => 'Stock', 'align' => 'right', 'format' => 'number'],
                ['key' => 'reorder_level', 'label' => 'Reorder Lvl', 'align' => 'right', 'format' => 'number'],
                ['key' => 'unit_cost', 'label' => 'Unit Cost', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'stock_value', 'label' => 'Stock Value', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'stock_status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
            ],
            'rows' => $rows->values()->all(),
        ];
    }

    private function lowStock(string $search, array $filters): array
    {
        $inventory = $this->medicineInventory($search, $filters);
        $lowRows = collect($inventory['rows'])
            ->filter(fn (array $row) => $row['current_stock'] <= $row['reorder_level'])
            ->map(function (array $row): array {
                $shortage = max(round($row['reorder_level'] - $row['current_stock'], 2), 0.0);

                return [
                    ...$row,
                    'shortage' => $shortage,
                    'estimated_restock_cost' => round($shortage * $row['unit_cost'], 2),
                ];
            })
            ->sortBy('current_stock')
            ->values();

        return [
            'summary' => [
                ['key' => 'low_count', 'label' => 'Low Stock Items', 'value' => $lowRows->count(), 'format' => 'number', 'tone' => 'amber'],
                ['key' => 'out_of_stock', 'label' => 'Out of Stock', 'value' => $lowRows->where('current_stock', '<=', 0)->count(), 'format' => 'number', 'tone' => 'rose'],
                ['key' => 'total_shortage', 'label' => 'Total Units Short', 'value' => round((float) $lowRows->sum('shortage'), 2), 'format' => 'number', 'tone' => 'blue'],
                ['key' => 'restock_cost', 'label' => 'Est. Restock Cost', 'value' => round((float) $lowRows->sum('estimated_restock_cost'), 2), 'format' => 'currency', 'tone' => 'teal'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Current Stock vs. Reorder Level',
                    'type' => 'bar',
                    'labels' => $lowRows->take(8)->pluck('name')->all(),
                    'series' => [
                        ['name' => 'Current Stock', 'data' => $lowRows->take(8)->pluck('current_stock')->all()],
                        ['name' => 'Reorder Level', 'data' => $lowRows->take(8)->pluck('reorder_level')->all()],
                    ],
                ],
                'breakdown' => [
                    'title' => 'Severity Breakdown',
                    'type' => 'donut',
                    'items' => array_values(array_filter([
                        ['label' => 'Out of Stock', 'value' => $lowRows->where('stock_status', 'out_of_stock')->count()],
                        ['label' => 'Low Stock', 'value' => $lowRows->where('stock_status', 'low_stock')->count()],
                    ], fn (array $i) => $i['value'] > 0)),
                ],
            ],
            'columns' => [
                ['key' => 'sku', 'label' => 'SKU', 'align' => 'left', 'format' => 'text'],
                ['key' => 'name', 'label' => 'Medicine', 'align' => 'left', 'format' => 'text'],
                ['key' => 'category', 'label' => 'Category', 'align' => 'left', 'format' => 'text'],
                ['key' => 'current_stock', 'label' => 'Current Stock', 'align' => 'right', 'format' => 'number'],
                ['key' => 'reorder_level', 'label' => 'Reorder Level', 'align' => 'right', 'format' => 'number'],
                ['key' => 'shortage', 'label' => 'Shortage', 'align' => 'right', 'format' => 'number'],
                ['key' => 'estimated_restock_cost', 'label' => 'Est. Restock Cost', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'stock_status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
            ],
            'rows' => $lowRows->all(),
        ];
    }

    private function expiredMedicines(?CarbonImmutable $dateFrom, ?CarbonImmutable $dateTo, string $search, array $filters): array
    {
        $today = CarbonImmutable::today();
        $scope = $filters['expiry_scope'] ?? 'expired';

        $query = MedicineBatch::query()
            ->with(['medicine:id,medicine_category_id,sku,name,unit', 'medicine.category:id,name'])
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expires_at')
            ->orderBy('expires_at');

        if ($scope === 'expiring_30d') {
            $query->whereDate('expires_at', '>=', $today->toDateString())
                ->whereDate('expires_at', '<=', $today->addDays(30)->toDateString());
        } elseif ($scope === 'all_expired_and_soon') {
            $query->whereDate('expires_at', '<=', $today->addDays(30)->toDateString());
        } else {
            $query->whereDate('expires_at', '<', $today->toDateString());
        }

        if ($dateFrom) {
            $query->whereDate('expires_at', '>=', $dateFrom->toDateString());
        }
        if ($dateTo) {
            $query->whereDate('expires_at', '<=', $dateTo->toDateString());
        }
        if (! empty($filters['category_id'])) {
            $query->whereHas('medicine', fn ($mq) => $mq->where('medicine_category_id', (int) $filters['category_id']));
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('batch_number', 'like', "%{$search}%")
                    ->orWhereHas('medicine', fn ($mq) => $mq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
            });
        }

        $batches = $query->get()->map(function (MedicineBatch $batch) use ($today): array {
            $qty = round((float) $batch->quantity_remaining, 2);
            $cost = round((float) $batch->unit_cost, 2);
            $loss = round($qty * $cost, 2);
            $isExpired = $batch->expires_at->lt($today);

            return [
                'id' => $batch->id,
                'sku' => $batch->medicine?->sku ?? '—',
                'medicine_name' => $batch->medicine?->name ?? '—',
                'category' => $batch->medicine?->category?->name ?? 'Uncategorized',
                'batch_number' => $batch->batch_number,
                'received_at' => $batch->received_at?->toDateString() ?? '—',
                'expires_at' => $batch->expires_at->toDateString(),
                'days_expired' => $isExpired ? (int) $batch->expires_at->diffInDays($today) : -(int) $today->diffInDays($batch->expires_at),
                'quantity_remaining' => $qty,
                'unit' => $batch->medicine?->unit ?? 'units',
                'unit_cost' => $cost,
                'loss_value' => $loss,
                'status' => $isExpired ? 'expired' : 'expiring_soon',
            ];
        });

        $byCategory = $batches->groupBy('category')->map(fn (Collection $g, string $cat): array => [
            'label' => $cat,
            'value' => round((float) $g->sum('loss_value'), 2),
        ])->values();

        return [
            'summary' => [
                ['key' => 'batches', 'label' => 'Affected Batches', 'value' => $batches->count(), 'format' => 'number', 'tone' => 'rose'],
                ['key' => 'units', 'label' => 'Affected Units', 'value' => round((float) $batches->sum('quantity_remaining'), 2), 'format' => 'number', 'tone' => 'amber'],
                ['key' => 'loss_value', 'label' => 'Total Stock Value', 'value' => round((float) $batches->sum('loss_value'), 2), 'format' => 'currency', 'tone' => 'rose'],
                ['key' => 'expired_only', 'label' => 'Already Expired', 'value' => $batches->where('status', 'expired')->count(), 'format' => 'number', 'tone' => 'blue'],
            ],
            'charts' => [
                'trend' => [
                    'title' => 'Affected Value by Medicine',
                    'type' => 'bar',
                    'labels' => $batches->take(8)->pluck('medicine_name')->all(),
                    'series' => [['name' => 'Batch Value', 'data' => $batches->take(8)->pluck('loss_value')->all()]],
                ],
                'breakdown' => [
                    'title' => 'Value by Category',
                    'type' => 'donut',
                    'items' => $byCategory->filter(fn (array $i) => $i['value'] > 0)->all(),
                ],
            ],
            'columns' => [
                ['key' => 'sku', 'label' => 'SKU', 'align' => 'left', 'format' => 'text'],
                ['key' => 'medicine_name', 'label' => 'Medicine', 'align' => 'left', 'format' => 'text'],
                ['key' => 'category', 'label' => 'Category', 'align' => 'left', 'format' => 'text'],
                ['key' => 'batch_number', 'label' => 'Batch No.', 'align' => 'left', 'format' => 'text'],
                ['key' => 'expires_at', 'label' => 'Expiry Date', 'align' => 'left', 'format' => 'date'],
                ['key' => 'quantity_remaining', 'label' => 'Qty Remaining', 'align' => 'right', 'format' => 'number'],
                ['key' => 'unit_cost', 'label' => 'Unit Cost', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'loss_value', 'label' => 'Stock Value', 'align' => 'right', 'format' => 'currency'],
                ['key' => 'status', 'label' => 'Status', 'align' => 'left', 'format' => 'status'],
            ],
            'rows' => $batches->values()->all(),
        ];
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @param  callable(T): ?string  $dateResolver
     * @param  null|callable(T): float  $valueResolver
     * @return array{labels: array<int, string>, counts: array<int, int>, sums: array<int, float>}
     */
    private function groupTrend(Collection $items, callable $dateResolver, string $period, ?callable $valueResolver = null): array
    {
        $buckets = [];

        foreach ($items as $item) {
            $rawDate = $dateResolver($item);
            if (! $rawDate) {
                continue;
            }
            $dt = CarbonImmutable::parse($rawDate);
            [$sortKey, $label] = match ($period) {
                'daily' => [$dt->format('Y-m-d'), $dt->format('M d')],
                'weekly' => [$dt->startOfWeek()->format('Y-m-d'), 'Wk '.$dt->startOfWeek()->format('M d')],
                'yearly' => [$dt->format('Y'), $dt->format('Y')],
                default => [$dt->format('Y-m'), $dt->format('M Y')],
            };

            if (! isset($buckets[$sortKey])) {
                $buckets[$sortKey] = ['label' => $label, 'count' => 0, 'sum' => 0.0];
            }
            $buckets[$sortKey]['count']++;
            if ($valueResolver !== null) {
                $buckets[$sortKey]['sum'] += $valueResolver($item);
            }
        }

        ksort($buckets);

        return [
            'labels' => array_column($buckets, 'label'),
            'counts' => array_column($buckets, 'count'),
            'sums' => array_map(fn (float $v) => round($v, 2), array_column($buckets, 'sum')),
        ];
    }
}
