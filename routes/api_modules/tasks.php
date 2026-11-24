<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('tasks')->group(function () { // must be above the resource route
    Route::post('/external-backup-pattern-preview', 'ExternalBackupPatternPreviewController@preview');
    Route::get('/external-backup-rules', 'ExternalBackupRuleController@index');
    Route::post('/external-backup-rules', 'ExternalBackupRuleController@store');
    Route::patch('/external-backup-rules/{externalBackupRule}', 'ExternalBackupRuleController@update');
    Route::delete('/external-backup-rules/{externalBackupRule}', 'ExternalBackupRuleController@destroy');
    Route::patch('/external-backup-rules/{externalBackupRule}/activation', 'ExternalBackupRuleController@activation')->middleware(EnsureUserIsAdmin::class);
    Route::get('/external-backup-rules/{externalBackupRule}/files-preview', 'ExternalBackupFilePreviewController@preview');
    Route::get('/external-backup-rules/{externalBackupRule}/runs', 'ExternalBackupRunHistoryController@index');
    Route::post('/external-backup-rules/{externalBackupRule}/verify-now', 'ExternalBackupManualProbeController@check')->middleware('throttle:6,1');
    Route::post('/validate-task', 'TaskController@validateTask');
    Route::post('/run-manual-task', 'TaskManualRunController@runManualTask');
    Route::get('/recent-failed-jobs-count', 'TaskController@failedJobsLast24HrsCount');
    Route::resource('monitored', 'MonitoredScheduledTaskLogItemController');
    Route::get('/toggle-pause-task/{id}', 'TaskController@togglePauseTask');
    Route::post('/delete-many', 'TaskController@deleteMany');
});

Route::resource('tasks', 'TaskController');
