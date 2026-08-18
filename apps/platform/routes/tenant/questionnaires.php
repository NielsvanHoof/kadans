<?php

declare(strict_types=1);

use App\Questionnaires\Http\Controllers\QuestionnaireChecklistDraftController;
use App\Questionnaires\Http\Controllers\QuestionnaireTemplateController;
use App\Questionnaires\Http\Controllers\QuestionnaireTemplateItemController;
use Illuminate\Support\Facades\Route;

/*
 * Template routes are intentionally not scoped under the tenant: system
 * templates (tenant_id null) must stay visible. QuestionnaireTemplate::
 * resolveRouteBinding() and Tenant::resolveChildRouteBinding() handle that.
 */
Route::get('templates', [QuestionnaireTemplateController::class, 'index'])->name('templates.index');
Route::get('templates/create', [QuestionnaireTemplateController::class, 'create'])->name('templates.create');
Route::post('templates', [QuestionnaireTemplateController::class, 'store'])->name('templates.store');
Route::get('templates/{template}', [QuestionnaireTemplateController::class, 'show'])->name('templates.show');
Route::put('templates/{template}', [QuestionnaireTemplateController::class, 'update'])->name('templates.update');
Route::delete('templates/{template}', [QuestionnaireTemplateController::class, 'destroy'])->name('templates.destroy');
Route::post('templates/{template}/copy', [QuestionnaireTemplateController::class, 'copy'])->name('templates.copy');
Route::post('templates/{template}/items/reorder', [QuestionnaireTemplateItemController::class, 'reorder'])
    ->name('templates.items.reorder');
Route::post('templates/{template}/items', [QuestionnaireTemplateItemController::class, 'store'])
    ->name('templates.items.store');
Route::post('templates/{template}/draft-items', [QuestionnaireChecklistDraftController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('templates.draft-items.store');

// Nested item routes: item must belong to the parent template.
Route::scopeBindings()->group(function (): void {
    Route::put('templates/{template}/items/{item}', [QuestionnaireTemplateItemController::class, 'update'])
        ->name('templates.items.update');
    Route::delete('templates/{template}/items/{item}', [QuestionnaireTemplateItemController::class, 'destroy'])
        ->name('templates.items.destroy');
});
