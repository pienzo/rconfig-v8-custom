<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\MonitoredScheduledTasks;
use App\Models\Task;
use App\Services\ExternalBackups\LocalBackupPresenceOccurrenceClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\CronTranslator\CronTranslator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TaskController extends ApiBaseController
{
    public const F02_TASK_COMMANDS = [
        ExternalBackupRuleController::TASK_COMMAND,
        LocalBackupPresenceOccurrenceClaim::TASK_COMMAND,
    ];

    public function __construct(Task $model, $modelname = 'task')
    {
        $this->model = $model;
        $this->modelname = $modelname;
    }

    public function index(Request $request, $searchCols = null, $relationship = null, $withCount = null)
    {
        $searchCols = ['id', 'task_name'];

        $result = QueryBuilder::for(Task::class)
            ->allowedFilters(...[
                AllowedFilter::custom('q', new FilterMultipleFields, 'task_name', 'task_command'),
                AllowedFilter::partial('task_command'),
            ])
            ->defaultSort('-id')
            ->allowedSorts(...['id', 'task_name', 'created_at'])
            ->paginate($request->perPage ?? 10);

        $result->map(function ($item) {
            $item['cron_plain'] = CronTranslator::translate(implode(' ', $item['task_cron']));

            return $item;
        });

        return response()->json($result);
    }

    public function store(StoreTaskRequest $request)
    {
        $model = parent::storeResource($request->toDTO()->toArray(), 1);

        $model->tag()->attach($request->tag);
        $model->device()->attach($request->device);
        $model->category()->attach($request->category);

        $this->SyncToMonitoredTasks($model);

        return $this->successResponse(Str::ucfirst($this->modelname) . ' created successfully!', ['id' => $model->id]);
    }

    public function validateTask(StoreTaskRequest $request)
    {
        $model = $request->toDTO()->toArray();

        return $this->successResponse(Str::ucfirst($this->modelname) . ' validated successfully!');
    }

    // get failed tasks
    public function failedJobsLast24HrsCount()
    {
        $res = DB::table('failed_jobs')->where('failed_at', '>=', Carbon::now()->subDays(1))->count();

        return $this->successResponse('Success', $res);
    }

    public function show($id, $relationship = null, $withCount = null)
    {
        return parent::show($id);  // relationships are pulled from the model using 'protected $with' now
    }

    public function update($id, UpdateTaskRequest $request)
    {
        if (Task::query()->whereKey($id)->whereIn('task_command', self::F02_TASK_COMMANDS)->exists()) {
            return response()->json(['message' => 'Edit external file checks from the F02 rules panel.'], 409);
        }

        $model = parent::updateResource($id, $request->toDTO()->toArray(), 1);
        $model->id = $id;

        $model->tag()->sync($request->tag);
        $model->device()->sync($request->device);
        $model->category()->sync($request->category);

        $this->SyncToMonitoredTasks($model);

        return $this->successResponse(Str::ucfirst($this->modelname) . ' edited successfully!', ['id' => $model->id]);
    }

    public function destroy($id, $return = 0)
    {
        if (Task::query()->whereKey($id)->whereIn('task_command', self::F02_TASK_COMMANDS)->exists()) {
            return response()->json(['message' => 'Delete external file checks from the F02 rules panel.'], 409);
        }

        $model = parent::destroy($id, 1);

        $model->delete();
        $model->tag()->detach();
        $model->category()->detach();
        $model->tag()->detach();
        $model->device()->detach();

        $model->delete();

        return $this->successResponse(Str::ucfirst($this->modelname) . ' deleted successfully!');
    }

    public function deleteMany(Request $request)
    {
        $data = $request->validate([
            'ids' => ['present', 'array'],
            'ids.*' => ['required', 'integer', 'min:1'],
        ]);

        // The inherited bulk endpoint deletes model instances directly, not
        // through destroy(). Refuse the entire selection before any deletion.
        return DB::transaction(function () use ($data) {
            $items = Task::query()->whereIn('id', $data['ids'])->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                if (in_array($item->task_command, self::F02_TASK_COMMANDS, true)) {
                    return response()->json(['message' => 'Delete external file checks from the F02 rules panel.'], 409);
                }
            }

            foreach ($items as $item) {
                $item->delete();
            }

            return response()->json(['message' => $this->modelname . 'deleted successfully'], 200);
        });
    }

    public function togglePauseTask($id)
    {
        if (Task::query()->whereKey($id)->whereIn('task_command', self::F02_TASK_COMMANDS)->exists()) {
            return response()->json(['message' => 'F02 execution is not available yet.'], 409);
        }

        $model = $this->model->find($id);
        $model->is_paused = ! $model->is_paused;
        $model->save();
        $responseText = $model->is_paused ? 'paused' : 'unpaused';

        return $this->successResponse(Str::ucfirst($this->modelname) . ' ' . $responseText . ' successfully!');
    }

    private function SyncToMonitoredTasks($model)
    {
        MonitoredScheduledTasks::updateOrCreate(
            ['task_id' => $model->id],
            [
                'task_id' => $model->id,
                'name' => $model->task_name,
                'cron_expression' => implode(' ', $model->task_cron),
                'type' => $model->task_command,
            ]
        );
    }
}
