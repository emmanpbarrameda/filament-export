<?php

namespace AlperenErsoy\FilamentExport\Actions;

use AlperenErsoy\FilamentExport\Actions\Concerns\CanDisableAdditionalColumns;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanDisableFileName;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanDisableFileNamePrefix;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanDisableFilterColumns;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanDisableFormats;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanDisablePreview;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanDisableTableColumns;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanDownloadDirect;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanFormatStates;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanHaveExtraColumns;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanHaveExtraViewData;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanModifyWriters;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanRefreshTable;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanShowHiddenColumns;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanUseSnappy;
use AlperenErsoy\FilamentExport\Actions\Concerns\CanExcludeColumns;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasAdditionalColumnsField;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasDefaultFormat;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasDefaultPageOrientation;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasExportModelActions;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasFileName;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasFileNameField;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasFilterColumnsField;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasFormatField;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasPageOrientationField;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasPaginator;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasTimeFormat;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasUniqueActionId;
use AlperenErsoy\FilamentExport\Actions\Concerns\HasCsvDelimiter;

use AlperenErsoy\FilamentExport\FilamentExport;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilamentExportBulkAction extends \Filament\Tables\Actions\BulkAction
{
    use CanDisableAdditionalColumns;
    use CanDisableFileName;
    use CanDisableFileNamePrefix;
    use CanDisableFilterColumns;
    use CanDisableFormats;
    use CanDisablePreview;
    use CanDisableTableColumns;
    use CanDownloadDirect;
    use CanFormatStates;
    use CanHaveExtraColumns;
    use CanHaveExtraViewData;
    use CanModifyWriters;
    use CanRefreshTable;
    use CanShowHiddenColumns;
    use CanUseSnappy;
    use CanExcludeColumns;
    use HasAdditionalColumnsField;
    use HasCsvDelimiter;
    use HasDefaultFormat;
    use HasDefaultPageOrientation;
    use HasExportModelActions;
    use HasFileName;
    use HasFileNameField;
    use HasFilterColumnsField;
    use HasFormatField;
    use HasPageOrientationField;
    use HasPaginator;
    use HasTimeFormat;
    use HasUniqueActionId;

    // --- REPORT TITLE
    protected string $reportTitle = '';
    public function reportTitle(string $title): static
    {
        $this->reportTitle = $title;

        return $this;
    }
    public function getReportTitle(): string
    {
        return $this->reportTitle;
    }


    protected function setUp(): void
    {
        parent::setUp();

        $this->uniqueActionId('bulk-action');

        FilamentExport::setUpFilamentExportAction($this);

        $this
            ->form(static function ($action, $records, $livewire): array {
                if ($action->shouldDownloadDirect()) {
                    return [];
                }

                $currentPage = LengthAwarePaginator::resolveCurrentPage('exportPage');

                $paginator = new LengthAwarePaginator($records->forPage($currentPage, $livewire->tableRecordsPerPage), $records->count(), $livewire->tableRecordsPerPage, $currentPage, [
                    'pageName' => 'exportPage',
                ]);

                $action->paginator($paginator);

                $formComponents = FilamentExport::getFormComponents($action);

                // --- EXCLUDE COLUMNS
                // Remove excluded columns from the filter_columns checkbox list
                if (isset($formComponents['filter_columns'])) {
                    $formComponents['filter_columns']->options(
                        collect($formComponents['filter_columns']->getOptions())
                            ->except($action->getExcludedColumns())
                            ->toArray()
                    );
                }
                return $formComponents;

            })
            ->action(static function ($action, $records, $data): StreamedResponse {
                $action->fillDefaultData($data);

                return FilamentExport::callDownload($action, $records, $data);
            });
    }
}
