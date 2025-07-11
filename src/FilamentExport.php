<?php

namespace AlperenErsoy\FilamentExport;


use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;
use AlperenErsoy\FilamentExport\Actions\FilamentExportHeaderAction;
use AlperenErsoy\FilamentExport\Components\TableView;
use AlperenErsoy\FilamentExport\Concerns\CanDisableTableColumns;
use AlperenErsoy\FilamentExport\Concerns\CanFilterColumns;
use AlperenErsoy\FilamentExport\Concerns\CanFormatStates;
use AlperenErsoy\FilamentExport\Concerns\CanHaveAdditionalColumns;
use AlperenErsoy\FilamentExport\Concerns\CanHaveExtraColumns;
use AlperenErsoy\FilamentExport\Concerns\CanHaveExtraViewData;
use AlperenErsoy\FilamentExport\Concerns\CanModifyWriters;
use AlperenErsoy\FilamentExport\Concerns\CanShowHiddenColumns;
use AlperenErsoy\FilamentExport\Concerns\CanUseSnappy;
use AlperenErsoy\FilamentExport\Concerns\HasCsvDelimiter;
use AlperenErsoy\FilamentExport\Concerns\HasData;
use AlperenErsoy\FilamentExport\Concerns\HasFileName;
use AlperenErsoy\FilamentExport\Concerns\HasFormat;
use AlperenErsoy\FilamentExport\Concerns\HasPageOrientation;
use AlperenErsoy\FilamentExport\Concerns\HasPaginator;
use AlperenErsoy\FilamentExport\Concerns\HasTable;
use Carbon\Carbon;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Filament\Notifications\Notification;

use AlperenErsoy\FilamentExport\Actions\Concerns\CanExcludeColumns; // --- EXCLUDE COLUMNS=

class FilamentExport
{
    use CanFilterColumns;
    use CanFormatStates;
    use CanHaveAdditionalColumns;
    use CanHaveExtraColumns;
    use CanHaveExtraViewData;
    use CanModifyWriters;
    use CanShowHiddenColumns;
    use CanDisableTableColumns;
    use CanUseSnappy;
    use CanExcludeColumns; // --- EXCLUDE COLUMNS
    use HasCsvDelimiter;
    use HasData;
    use HasFileName;
    use HasFormat;
    use HasPageOrientation;
    use HasPaginator;
    use HasTable;

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

    public const DEFAULT_FORMATS = [
        'xlsx' => 'XLSX',
        'csv' => 'CSV',
        'pdf' => 'PDF',
    ];

    public static function make(): static
    {
        $static = app(static::class);
        $static->setUp();

        return $static;
    }

    protected function setUp(): void
    {
        $this->fileName(Date::now()->toString());

        $this->format(config('filament-export.default_format'));
    }

    public function getAllColumns(): Collection
    {
        if ($this->isTableColumnsDisabled()) {
            $tableColumns = [];
        } else {
            $tableColumns = $this->shouldShowHiddenColumns() ? $this->getTable()->getColumns() : $this->getTable()->getVisibleColumns();
        }

        $columns = collect($tableColumns);

        if ($this->getWithColumns()->isNotEmpty()) {
            $columns = $columns->merge($this->getWithColumns());
        }

        if ($this->getFilteredColumns()->isNotEmpty()) {
            $columns = $columns->filter(fn ($column) => $this->getFilteredColumns()->contains($column->getName()));
        }

        if ($this->getAdditionalColumns()->isNotEmpty()) {
            $columns = $columns->merge($this->getAdditionalColumns());
        }

        // --- EXCLUDE COLUMNS
        $columns = $columns->filter(fn ($column) => !in_array($column->getName(), $this->getExcludedColumns()));

        return $columns;
    }

    public function getPdfView(): string
    {
        return 'filament-export::pdf';
    }

    public function getViewData(): array
    {
        return array_merge(
            [
                'fileName' => $this->getFileName(),
                'columns' => $this->getAllColumns(),
                'rows' => $this->getRows(),
                'reportTitle' => $this->getReportTitle(), // --- REPORT TITLE
            ],
            $this->getExtraViewData()
        );
    }



    public function download(): StreamedResponse
    {
        if ($this->getFormat() === 'pdf') {
            $pdf = $this->getPdf();
    
            if ($modifyPdf = $this->getModifyPdfWriter()) {
                $pdf = $modifyPdf($pdf);
            }
    
            $response = response()->streamDownload(fn () => print($pdf->output()), "{$this->getFileName()}.{$this->getFormat()}");
        } else {
            $response = response()->streamDownload(function () {
                $reportTitle = $this->getReportTitle(); // --- REPORT TITLE
                $headers = $this->getAllColumns()->map(fn ($column) => $column->getLabel())->toArray();
    
                $currentLoggedUserName = \Illuminate\Support\Facades\Auth::user()->name;
                $formattedDateTime = \Carbon\Carbon::now()->setTimezone('Asia/Manila')->format('F j, Y (l) · h:i:s A');
                $infoDateAndUser = $formattedDateTime . '  ·  ' . 'Printed by ' . $currentLoggedUserName;
    
                $stream = SimpleExcelWriter::streamDownload("{$this->getFileName()}.{$this->getFormat()}", $this->getFormat(), delimiter: $this->getCsvDelimiter())
                    ->noHeaderRow();
    
                // Add report title as the first row if it exists
                if (!empty($reportTitle)) {
                    $stream->addRow([$reportTitle]);
                    // $stream->addRow([$infoDateAndUser]);
                    $stream->addRow([]); // Add an empty row for spacing
                }
    
                // Add column headers
                $stream->addRow($headers);
    
                // Add data rows
                $stream->addRows($this->getRows());
    
                // // Add footer with date, time, and user name
                // $stream->addRow([]);
                // $stream->addRow([$infoDateAndUser]);
    
                if ($modifyExcel = $this->getModifyExcelWriter()) {
                    $stream = $modifyExcel($stream);
                }
    
                $stream->close();
            }, "{$this->getFileName()}.{$this->getFormat()}");
        }
    
        // Add notification after preparing the response
        Notification::make()
            ->title("Report '<b>{$this->getFileName()}.{$this->getFormat()}</b>' Downloaded Successfully!")
            ->success()
            ->send()
            ->sendToDatabase(\App\Filament\Resources\VariableStorerResource::getCurrentLoggedUserNotifRecipient());
    
        return $response;
    }


    /********************************************
     * GENERATE PDF REPORTS USING Snappy or Dom PDF
     * 
     * connected to:
     *  pdf_header.blade.php
     *  pdf_footer.blade.php
     */
    public function getPdf(): \Barryvdh\DomPDF\PDF | \Barryvdh\Snappy\PdfWrapper
    {
        if ($this->shouldUseSnappy()) {
            $viewData = $this->getViewData();
            $headerHtml = view('reports.pdf_header', $viewData)->render();
            $footerHtml = view('reports.pdf_footer', $viewData)->render();
            
            $headerPath = tempnam(sys_get_temp_dir(), 'header') . '.html';
            $footerPath = tempnam(sys_get_temp_dir(), 'footer') . '.html';
            
            file_put_contents($headerPath, $headerHtml);
            file_put_contents($footerPath, $footerHtml);
    
            // text for footer left
            $currentLoggedUserName = \Illuminate\Support\Facades\Auth::user()->name;
            $formattedDateTime = \Carbon\Carbon::now()->setTimezone('Asia/Manila')->format('F j, Y (l) · h:i:s A');
            $footerLeftText = $formattedDateTime . '  ·  ' .'Printed by ' . $currentLoggedUserName;

            // Ensure file paths use forward slashes
            $headerPath = str_replace('\\', '/', $headerPath);
            $footerPath = str_replace('\\', '/', $footerPath);
    
            // https://wkhtmltopdf.org/usage/wkhtmltopdf.txt
            $pdf = \Barryvdh\Snappy\Facades\SnappyPdf::setOptions([
                    'encoding' => 'utf-8',
                    'enable-local-file-access' => true,
                    'margin-bottom' => '10mm',
                ])
                ->loadView($this->getPdfView(), $this->getViewData())
                ->setPaper('A4', $this->getPageOrientation())
                ->setOption('header-html', 'file:///' . $headerPath)
                ->setOption('header-spacing', 5)
                // ->setOption('header-center', 'Testing')
                ->setOption('footer-font-size', 8)
                ->setOption('footer-html', 'file:///' . $footerPath)
                ->setOption('footer-left', $footerLeftText)
                ->setOption('footer-right', 'Page [page] of [toPage]');
    
            // Cleanup temporary files
            register_shutdown_function(function() use ($headerPath, $footerPath) {
                @unlink($headerPath);
                @unlink($footerPath);
            });

            // Notifications
            // Notification::make()
            //     ->title("PDF Report '<b>{$this->getFileName()}.{$this->getFormat()}</b>' Downloaded Successfully!")
            //     ->success()->send()
            //     ->sendToDatabase(\App\Filament\Resources\VariableStorerResource::getCurrentLoggedUserNotifRecipient());
        
            return $pdf;
        }
    
        // DomPDF option remains unchanged (NOW USING SNAPPY PDF)
        // return \Barryvdh\DomPDF\Facade\Pdf::loadView($this->getPdfView(), $this->getViewData())
        return \Barryvdh\Snappy\Facades\SnappyPdf::loadView($this->getPdfView(), $this->getViewData())
            ->setPaper('A4', $this->getPageOrientation());
    }
    
    
    

    public static function setUpFilamentExportAction(FilamentExportHeaderAction | FilamentExportBulkAction $action): void
    {
        $action->timeFormat(config('filament-export.time_format'));

        $action->defaultFormat(config('filament-export.default_format'));

        $action->defaultPageOrientation(config('filament-export.default_page_orientation'));

        $action->disableAdditionalColumns(config('filament-export.disable_additional_columns'));

        $action->disableFilterColumns(config('filament-export.disable_filter_columns'));

        $action->disableFileName(config('filament-export.disable_file_name'));

        $action->disableFileNamePrefix(config('filament-export.disable_file_name_prefix'));

        $action->disablePreview(config('filament-export.disable_preview'));

        $action->snappy(config('filament-export.use_snappy', false));

        $action->icon(config('filament-export.action_icon'));

        $action->fileName(Carbon::now()->translatedFormat($action->getTimeFormat()));

        $action->fileNameFieldLabel(__('filament-export::export_action.file_name_field_label'));

        $action->filterColumnsFieldLabel(__('filament-export::export_action.filter_columns_field_label'));

        $action->formatFieldLabel(__('filament-export::export_action.format_field_label'));

        $action->pageOrientationFieldLabel(__('filament-export::export_action.page_orientation_field_label'));

        $action->additionalColumnsFieldLabel(__('filament-export::export_action.additional_columns_field.label'));

        $action->additionalColumnsTitleFieldLabel(__('filament-export::export_action.additional_columns_field.title_field_label'));

        $action->additionalColumnsDefaultValueFieldLabel(__('filament-export::export_action.additional_columns_field.default_value_field_label'));

        $action->additionalColumnsAddButtonLabel(__('filament-export::export_action.additional_columns_field.add_button_label'));

        $action->modalSubmitActionLabel(__('filament-export::export_action.export_action_label'));

        $action->modalHeading(__('filament-export::export_action.modal_heading'));

        $action->modalFooterActions($action->getExportModalActions());
    }

    public static function getFormComponents(FilamentExportHeaderAction | FilamentExportBulkAction $action): array
    {
        $action->fileNamePrefix($action->getFileNamePrefix() ?: $action->getTable()->getHeading());

        if ($action->isTableColumnsDisabled()) {
            $columns = [];
        } else {
            $columns = $action->shouldShowHiddenColumns() ? $action->getTable()->getColumns() : $action->getTable()->getVisibleColumns();
        }
        $columns = $action->shouldShowHiddenColumns() ? $action->getTable()->getColumns() : $action->getTable()->getVisibleColumns();

        $columns = collect($columns);

        $extraColumns = collect($action->getWithColumns());

        if($extraColumns->isNotEmpty()) {
            $columns = $columns->merge($extraColumns);
        }

        $columns = $columns
            ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])
            ->toArray();

        $updateTableView = function ($component, $livewire) use ($action) {
            /** @var \AlperenErsoy\FilamentExport\Components\TableView $component */
            /** @var \Filament\Resources\Pages\ListRecords $livewire */
            $data = $action instanceof FilamentExportBulkAction ? $livewire->getMountedTableBulkActionForm()->getState() : $livewire->getMountedTableActionForm()->getState();

            $export = FilamentExport::make()
                ->filteredColumns($data['filter_columns'] ?? [])
                ->additionalColumns($data['additional_columns'] ?? [])
                ->data(collect())
                ->table($action->getTable())
                ->disableTableColumns($action->isTableColumnsDisabled())
                ->extraViewData($action->getExtraViewData())
                ->withColumns($action->getWithColumns())
                ->paginator($action->getPaginator())
                ->csvDelimiter($action->getCsvDelimiter())
                ->formatStates($action->getFormatStates())
                ->excludeColumns($action->getExcludedColumns()) // --- EXCLUDE COLUMNS
                ->reportTitle($action->getReportTitle()); // --- REPORT TITLE

                if ($data['table_view'] == 'print-' . $action->getUniqueActionId()) {
                    $export->data($action->getRecords());
                    
                    // Get all columns and filter out the excluded ones
                    // --- EXCLUDE COLUMNS
                    $allColumns = $export->getAllColumns();
                    $excludedColumns = $action->getExcludedColumns();
                    $filteredColumns = $allColumns->reject(function ($column) use ($excludedColumns) {
                        return in_array($column->getName(), $excludedColumns);
                    });
            
                    // PRINT VIEW STYLE
                    $printHTML = view('filament-export::print', array_merge(
                        $export->getViewData(),
                        [
                            'reportTitle' => $action->getReportTitle(),
                            'columns' => $filteredColumns, // Use filtered columns here
                        ]
                    ))->render();
                } else {
                    $printHTML = '';
                }

            $livewire->resetPage('exportPage');

            $component
                ->export($export)
                ->refresh($action->shouldRefreshTableView())
                ->printHTML($printHTML);
        };

        $initialExport = FilamentExport::make()
            ->table($action->getTable())
            ->disableTableColumns($action->isTableColumnsDisabled())
            ->data(collect())
            ->extraViewData($action->getExtraViewData())
            ->withColumns($action->getWithColumns())
            ->paginator($action->getPaginator())
            ->csvDelimiter($action->getCsvDelimiter())
            ->formatStates($action->getFormatStates());

        return [
            \Filament\Forms\Components\TextInput::make('file_name')
                ->label($action->getFileNameFieldLabel())
                ->default($action->getFileName())
                ->hidden($action->isFileNameDisabled())
                ->rule('regex:/[a-zA-Z0-9\s_\\.\-\(\):]/')
                ->required(),
            \Filament\Forms\Components\Select::make('format')
                ->label($action->getFormatFieldLabel())
                ->options($action->getFormats())
                ->default($action->getDefaultFormat())
                ->reactive(),
            \Filament\Forms\Components\Select::make('page_orientation')
                ->label($action->getPageOrientationFieldLabel())
                ->options(FilamentExport::getPageOrientations())
                ->default($action->getDefaultPageOrientation())
                ->visible(fn ($get) => $get('format') === 'pdf'),
            \Filament\Forms\Components\CheckboxList::make('filter_columns')
                ->label($action->getFilterColumnsFieldLabel())
                ->options($columns)
                ->columns(4)
                ->default(array_keys($columns))
                ->hidden($action->isFilterColumnsDisabled()),
            \Filament\Forms\Components\KeyValue::make('additional_columns')
                ->label($action->getAdditionalColumnsFieldLabel())
                ->keyLabel($action->getAdditionalColumnsTitleFieldLabel())
                ->valueLabel($action->getAdditionalColumnsDefaultValueFieldLabel())
                ->addActionLabel($action->getAdditionalColumnsAddButtonLabel())
                ->hidden($action->isAdditionalColumnsDisabled()),
            TableView::make('table_view')
                ->export($initialExport)
                ->uniqueActionId($action->getUniqueActionId())
                ->afterStateUpdated($updateTableView)
                ->reactive()
                ->refresh($action->shouldRefreshTableView()),
        ];
    }

    public static function callDownload(FilamentExportHeaderAction | FilamentExportBulkAction $action, Collection $records, array $data)
    {
        return FilamentExport::make()
            ->fileName($data['file_name'] ?? $action->getFileName())
            ->data($records)
            ->table($action->getTable())
            ->disableTableColumns($action->isTableColumnsDisabled())
            ->filteredColumns(! $action->isFilterColumnsDisabled() ? $data['filter_columns'] : [])
            ->additionalColumns(! $action->isAdditionalColumnsDisabled() ? $data['additional_columns'] : [])
            ->format($data['format'] ?? $action->getDefaultFormat())
            ->pageOrientation($data['page_orientation'] ?? $action->getDefaultPageOrientation())
            ->snappy($action->shouldUseSnappy())
            ->extraViewData($action->getExtraViewData())
            ->withColumns($action->getWithColumns())
            ->withHiddenColumns($action->shouldShowHiddenColumns())
            ->csvDelimiter($action->getCsvDelimiter())
            ->modifyExcelWriter($action->getModifyExcelWriter())
            ->modifyPdfWriter($action->getModifyPdfWriter())
            ->formatStates($action->getFormatStates())
            ->excludeColumns($action->getExcludedColumns()) // --- EXCLUDE COLUMNS
            ->reportTitle($action->getReportTitle()) // --- REPORT TITLE
            ->download();
    }

    public function getRows(): Collection
    {
        $records = $this->getData();

        $data = self::getDataWithStates($records);

        return collect($data);
    }

    public function getDataWithStates(Collection|LengthAwarePaginator $records): array
    {
        $items = [];

        $columns = $this->getAllColumns();

        $formatStates  = $this->getFormatStates();

        foreach ($records as $index => $record) {
            $item = [];

            foreach ($columns as $column) {
                $state = self::getColumnState($this->getTable(), $column, $record, $index, $formatStates);

                $item[$column->getName()] = (string) $state;
            }
            array_push($items, $item);
        }

        return $items;
    }

    public static function getColumnState(Table $table, Column $column, Model $record, int $index, array $formatStates): ?string
    {
        $column->rowLoop((object) [
            'index' => $index,
            'iteration' => $index + 1,
        ]);

        $column->record($record);

        $column->table($table);

        if (array_key_exists($column->getName(), $formatStates) && $formatStates[$column->getName()] instanceof \Closure) {
            $closure = $formatStates[$column->getName()];

            $dependencies = [];

            foreach ((new \ReflectionFunction($closure))->getParameters() as $parameter) {
                switch ($parameter->getName()) {
                    case 'table':
                        $dependencies[] = $table;
                        break;
                    case 'column':
                        $dependencies[] = $column;
                        break;
                    case 'record':
                        $dependencies[] = $record;
                        break;
                    case 'index':
                        $dependencies[] = $index;
                        break;
                }
            }

            return $closure(...$dependencies);
        }

        $state = in_array(\Filament\Tables\Columns\Concerns\CanFormatState::class, class_uses($column)) ? $column->formatState($column->getState()) : $column->getState();

        if (is_array($state)) {
            $state = implode(', ', $state);
        } elseif ($column instanceof ImageColumn) {
            $state = $column->getImageUrl();
        } elseif ($column instanceof ViewColumn) {
            $state = trim(preg_replace('/\s+/', ' ', strip_tags($column->render()->render())));
        }

        return $state;
    }
}
