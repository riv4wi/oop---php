<?php

namespace Sitrack\Commands;

use Carbon\Carbon;
use Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Sitrack\Console\Command;
use Sitrack\Mail\Mailer;
use Sitrack\Messages\Messages;
use Sitrack\Resources\Resource;
use Sitrack\Utils\DateUtils;
use Sitrack\Utils\StringUtils;
use stdClass;

/**
 * Class HolderReportCommand - Comando para enviar correos sobre desempeño de holders
 * Ejecucion: php execute holder_report
 * Parametros Obligatorios:
 *      --clientid "16229"
 *      --timezone 3
 *      --frequency monthly
 *      --recipients "luis.rivas@sitrack.com,luis.rivas.h@gmail.com"
 *      --columns "[{\"dataField\":\"domain\", \"width\":15, \"label\":\"Domain\"}, {\"id\":\"name\", \"width\":15, \"label\":\"Name\" }, {\"id\":\"driver\", \"width\":40, \"label\":\"Driver\", \"defaultValue\":\"...\"}, {\"id\":\"travelDistance\"}, {\"id\":\"travelTime\", \"formatter\":\"time\"}, {\"id\":\"ralentiTime\", \"formatter\":\"time\"}]"
 * Parametros Opcionales:
 *      --lang pt
 *      --fileName "performance_report.xlsx"
 *      --subject "REPORTE DESEMPENHO"
 * @package Sitrack\Commands
 * @author Luis Rivas <luis.rivas@sitrack.com>
 */
abstract class GenericReportCommand extends Command {

    const WEEKLY_FRECUENCY = 'weekly';
    const MONTHLY_FRECUENCY = 'monthly';
    const ROW_TO_BEGIN = 41;
    const DEFAULT_COLUMN_WIDTH = 20;
    const LANGUAGE_DEFAULT = "pt";
    const SCOPE_HOLDER = 'holder';
    const SCOPE_CONTACT = 'contact';
    const FIRST_DAILY_ODOMETER_ACCUMULATOR_ID = "firstDailyOdometerWithContact";
    const FIRST_DAYLY_HOURMETER_ACCUMULATOR_ID = 'firstDailyHourmeterWithContact';


    /**
     * Devuelve la distancia recorrida por scope
     * @param $options Contiene la información necesaria para procesar la información
     * @param $holders Holders a los que tiene acceso el cliente
     * @return array
     */
    abstract public function getTravelDistanceDataByScopeId($options, $scopes) : array;

    /**
     * Devuelve el tiempo de viaje por holder - Tambien conocido como "Horas de uso"
     * @param $options Contiene la información necesaria para procesar la información
     * @param $holders Holders a los que tiene acceso el cliente
     * @return array
     */
    abstract public function getTravelTimeDataByScopeId($options, $scopes) : array;

    /**
     * Devuelve el tiempo en ralenti por holder
     * @param $options Contiene la información necesaria para procesar la información
     * @param $holders Holders a los que tiene acceso el cliente
     * @return array
     */
    abstract public function getRalentiTimeDataByScopeId($options, $scope) : array;

    /**
     * Devuelve el tiempo en ralenti por holder
     * @param $options Contiene la información necesaria para procesar la información
     * @param $holders Holders a los que tiene acceso el cliente
     * @return array
     */
    abstract public function getScopeData($options) : array;

    /**
     * Método que se encarga de procesar el comando
     * @param array $arguments argumentos del comando
     * @throws Exception
     */
    public function handle(array $arguments = []) {
        $options = $this->getOptions($arguments);
        $data = $this->getData($options);
        $spreadsheet = $this->createSpreadSheet($options, $data);
        $spreadsheetContents = $this->getSpreadsheetContents($spreadsheet);
        $this->sendMail($options, $spreadsheetContents);
    }

    /**
     * Obtiene un objeto con todos los parámetros y opciones que debe tomar en cuenta el comando para ejecutarse
     * @param $arguments
     * @return stdClass $options
     */
    private function getOptions($arguments) : stdClass{
        $session = get_session();

        $options = new stdClass();

        if (empty($arguments['scope'])) {
            $arguments['scope'] = 'holder';
        }
        $options->scope = $arguments['scope'];

        if (empty($arguments['timezone'])) {
            throw new RuntimeException("Parameter 'timezone' is required !");
        }
        $timezone = Resource::get('timezone')->where('timezoneid', $arguments['timezone'])->first();
        if (empty($timezone)) {
            throw new RuntimeException("The timezone parameter is incorrect or does not exist.");
        }
        $options->timezone = DateUtils::getTimeZoneByOffset($timezone->offset);
        $session->set("userTimeZone", $options->timezone);

        if (empty($arguments['frequency'])) {
            throw new RuntimeException("Parameter 'frecuency' is required !");
        }
        $options->frequency = $arguments['frequency'];

        $options->userLanguage = ! empty($arguments['lang']) ? $arguments['lang'] : self::LANGUAGE_DEFAULT;

        Messages::language($options->userLanguage); // Seteo de idioma para el archivo de traducciones.

        $options->fileName = ! empty($arguments['fileName']) ? $arguments['fileName'] : get_message("commands.holder_report.Performance_report.performance_report");

        $options->subject = ! empty($arguments['subject']) ? $arguments['subject'] : get_message("commands.holder_report.Performance_report.performance_report");

        switch ($options->frequency) {
            case self::MONTHLY_FRECUENCY:
                $options->dateFrom = Carbon::now($options->timezone)->subMonth()->startOfDay();
                break;
            case self::WEEKLY_FRECUENCY:
                $options->dateFrom = Carbon::now($options->timezone)->subWeek()->startOfDay();
                break;
        }

        $options->dateTo = Carbon::now($options->timezone)->endOfDay();

        if (empty($arguments['clientid'])) {
            throw new RuntimeException("Parameter 'clientid' is required !");
        }
        $options->clientId = $arguments['clientid'];
        $session->set("clientId", $options->clientId);

        if (isset($arguments['fleetId'])) {
            $options->fleetId = $arguments['fleetId'];
            if (empty($options->fleetId) || $options->fleetId <= 0) { // Valida que fleetId sea mayor que cero y no esté vacío
                throw new RuntimeException("Parameter fleetId must be numeric and greater than zero.");
            }
        }
        else {
            $options->fleetId = null;
        }

        if (empty($arguments['recipients'])) {
            throw new RuntimeException("Parameter 'recipients' is required !");
        }
        $options->recipients = explode(",", $arguments['recipients']);

        if (empty($arguments['columns'])) {
            throw new RuntimeException("Parameter 'columns' is required !");
        }
        $options->columns = json_decode($arguments['columns']);
        foreach ($options->columns as $i => $column) {
            if (is_string($column)) {
                $columnId = $column;
                $options->columns[$i] = new stdClass();
                $options->columns[$i]->id = $columnId;
            }
        }
        return $options;
    }

    /**
     * Permite obtener las opciones y parámetros que se especificaron en el comando
     * @param $options
     * @return array $holdersData
     */
    private function getData($options) : array {
        $data = null;
        $data = $this->getScopeData($options);

        if (!empty($data)) {
            $elementOfReference = reset($data);
            $columnsData = [];
            foreach ($options->columns as $column){
                $dataField = $column->dataField;
                if (preg_match_all("/{(\w*)}/", $dataField, $matches)) {
                    $extraDataFields = $matches[1];
                    foreach ($extraDataFields as $extraDataField) {
                        if (!isset($elementOfReference->$extraDataField)) {
                            $methodName = "get" . ucfirst($extraDataField) . "DataByScopeId";
                            if (method_exists($this, $methodName)) {
                                $columnsData[$extraDataField] = $this->$methodName($options, $data);
                            } else {
                                throw new RuntimeException("Data field \"" . $extraDataField . "\" not supported !");
                            }
                        }
                    }
                }
                else {
                    if (!isset($elementOfReference->$dataField)) {
                        $methodName = "get" . ucfirst($dataField) . "DataByScopeId";
                        if (method_exists($this, $methodName)) {
                            $columnsData[$dataField] = $this->$methodName($options, $data);
                        } else {
                            throw new RuntimeException("Data field \"" . $dataField . "\" not supported !");
                        }
                    }
                }
            }

            foreach ($data as $scopeId => $scopeData) {
                foreach ($options->columns as $column) {
                    $dataField = $column->dataField;
                    if (isset($columnsData[$dataField]) && isset($columnsData[$dataField][$scopeId])) {
                        $scopeData->$dataField = $columnsData[$dataField][$scopeId];
                    }
                }
            }
        }
        return $data;
    }

    /**
     * Configura los valores y estilos que debe tener la hoja de cálculo para renderizarla
     * @param $options Opciones y parámetros especificados en el comando
     * @param $data
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @return Spreadsheet $spreadsheet
     */
    private function createSpreadSheet ($options, $data) {
        $title = get_message("commands.holder_report.Performance_report");
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);

        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => array('rgb' => 'FFFFFF'),
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => array('argb' => '00000000'),
                ],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [ 'rgb' => '115edb' ]
            ]
        ];

        $normalStyle = [
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'font' => [
                'bold' => false,
            ],
        ];

        // Configuración del ancho de las columnas, si están configuraadas en el JSON que se pasa en el comando
        $columInLetter = 65; // Código ASCII de Letra 'A'
        foreach ($options->columns as $column) {
            $widthColumn = empty($column->width) ? self::DEFAULT_COLUMN_WIDTH : $column->width;
            $sheet->getColumnDimension(chr($columInLetter++))->setWidth($widthColumn);
        }

        $row = 1;
        $sheet->getRowDimension($row)->setRowHeight(24);
        foreach ($options->columns as $col => $column) {
            $cell = $sheet->getCellByColumnAndRow($col+1, $row);
            $label = empty($column->label) ? get_message("commands.holder_report." . ucfirst(StringUtils::toSnakeCase($column->dataField))) : $column->label;
            $cell->setValue($label);
            $cell->getStyle()->applyFromArray($headerStyle);
        }
        if (!empty($data)) {
            foreach ($data as $scopeId => $scopeData) {
                $row++;
                $sheet->getRowDimension($row)->setRowHeight(24);
                foreach ($options->columns as $col => $column) {
                    $cell = $sheet->getCellByColumnAndRow($col+1, $row);
                    $dataField = $column->dataField;
                    if (preg_match_all("/{(\w*)}/", $dataField, $matches)) {
                        $extraDataFields = $matches[1];
                        $value = $dataField;
                        foreach ($extraDataFields as $extraDataField) {
                            if (isset($scopeData->$extraDataField)) {
                                $value = str_replace("{" . $extraDataField . "}", $scopeData->$extraDataField, $value);
                            }
                        }
                    }
                    else if (isset($scopeData->$dataField)) {
                        $value = $scopeData->$dataField;
                        if (!empty($column->formatter)) {
                            $formatterMethodName = "format" . ucfirst($column->formatter);
                            $value = $this->$formatterMethodName($value);
                        }
                    }
                    else if (isset($column->defaultValue)) {
                        $value = $column->defaultValue;
                    }
                    else {
                        $value = "--";
                    }
                    $cell->setValue($value);
                    $cell->getStyle()->applyFromArray($normalStyle);
                }
            }
        }
        return $spreadsheet;
    }

    /**
     * Crea la hoja de cálculo y la manda a la salida
     * @param Spreadsheet $spreadsheet
     * @return string
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    private function getSpreadsheetContents($spreadsheet) {
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }

    /**
     * Envia un email con un excel adjunto
     * @param $options
     * @param $xlsData
     * @throws \PHPMailer\PHPMailer\Exception
     */
    private function sendMail($options, $xlsData) {
        $mailer = Mailer::create ();
        foreach($options->recipients as $recipient) {
            $mailer->addAddress ($recipient);
        }
        if (isset($options->ccRecipients)) {
            foreach($options->ccRecipients as $recipient) {
                $mailer->addCC($recipient);
            }
        }
        $mailer->Subject = $options->subject . " - " . $options->dateFrom->toDateTimeString() . " - " . $options->dateTo->toDateTimeString();
        $mailer->Body = " ";
        $mailer->addStringAttachment($xlsData, $options->fileName, 'base64');
        $mailer->send();
    }

    /*----------------------------------------------------------------------------------------------------------------*/
    /* Funciones usadas para formatear los valores de la hoja de cálculo. El formato viene especificado en el JSON que
    * está en el definido en el comando. Toda función que se use para formato debe tener el prefijo 'format' para
    * mantener la consistencia en la estructura del código
    /*----------------------------------------------------------------------------------------------------------------*/

    /**
     * Formatea un valor expresado en segundos en formato HH:MM:SS
     * @param $value Es el valor que se quiere formatear
     * @return string formateado
     */
    private function formatTime($value) : string {
        return DateUtils::formatSeconds($value);
    }
}

/*
./execute holder_report --clientid "16229" --scope contact --timezone 3 --frequency monthly --recipients "luis.rivas@sitrack.com" --columns "[{\"dataField\":\"{name} {lastname}\", \"width\":40, \"label\":\"Motorista\"}, {\"dataField\":\"holder\", \"width\":15, \"label\":\"Domain\"}, {\"dataField\":\"travelDistance\"}, {\"dataField\":\"travelTime\", \"formatter\":\"time\"}, {\"dataField\":\"ralentiTime\", \"formatter\":\"time\"}]" --lang pt --fileName "contacto.xlsx" --subject "REPORTE DESEMPENHO POR MOTORISTA"
./execute holder_report --clientid "16229" --scope holder  --timezone 3 --frequency monthly --recipients "luis.rivas@sitrack.com" --columns "[{\"dataField\":\"domain\", \"width\":15, \"label\":\"Domain\"}, {\"dataField\":\"name\", \"width\":15, \"label\":\"Name\" }, {\"dataField\":\"driver\", \"width\":40, \"label\":\"Driver\"}, {\"dataField\":\"travelDistance\"}, {\"dataField\":\"travelTime\", \"formatter\":\"time\"}, {\"dataField\":\"ralentiTime\", \"formatter\":\"time\"}]" --lang pt --fileName "holder.xlsx" --subject "REPORTE DESEMPENHO POR HOLDER"
*/