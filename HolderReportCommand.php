<?php
/**
 * Created by PhpStorm.
 * User: luis
 * Date: 17/05/19
 * Time: 17:37
 */

namespace Sitrack\Commands;


class HolderReportComand extends GenericReportCommand {

    /**
     * Devuelve un conjunto de holders pertenecientes a una flota
     * @param int $clientId Cliente ID
     * @param int|null $fleetId Id de la flota
     * @return mixed
     */
    public function getScopeData($options): array {
        $holdersQuery = Resource::get("holder");
        $holdersQuery->where("clientid", $options->clientId);
        if (!empty($options->fleetId)) {
            $holdersQuery->where("fleetid", $options->fleetId);
        }
        return $holdersQuery->find("holderid");
    }

    /**
     * Obtiene los datos de los conductores cuando el scope es holder
     * @param $options
     * @param array $scopes Contiene los holders del cliente
     * @return mixed
     */
    public function getDriverDataByScopeId($options, $scopes) {
        $driversData = [];
        $driverIds = array_unique(array_column($scopes, "driverid"));
        $contactsData = empty($driverIds) ? [] : Resource::get("contact")->whereIn("contactid", $driverIds)->pluck("{name} {lastname}", "contactid");
        foreach ($scopes as $holderId => $holder) {
            if (isset($contactsData[$holder->driverid])) {
                $driversData[$holderId] = $contactsData[$holder->driverid];
            }
        }
        return $driversData;
    }

    /**
     * Devuelve la distancia recorrida por scope
     * @param $options Contiene la información necesaria para procesar la información
     * @param $holders Holders a los que tiene acceso el cliente
     * @return array
     */
    public function getTravelDistanceDataByScopeId($options, $scopes) : array {
        $kilometersTraveled = []; // Arreglo indexado por scope que contiene los kilómetros recorridos
        $traveledDistanceData = Resource::get("metrics.holder_distance")
            ->whereIn("holderid", array_keys($scopes))
            ->where("dateFrom", ">=", $options->dateFrom)
            ->where("dateTo", "<=", $options->dateTo)
            ->groupBy("scopeid")
            ->find("scopeid");
        $kilometersTraveled = array_column($traveledDistanceData, "metric", "scopeid");
        return $kilometersTraveled;
    }

    /**
     * Devuelve el tiempo de viaie por holder - Tambien conocido como "Horas de uso"
     * @param $options Contiene la información necesaria para procesar la información
     * @param $holders Holders a los que tiene acceso el cliente
     * @return array
     */
    public function getTravelTimeDataByScopeId($options, $scopes) : array {
        $travelTime = [];
        $holderTravelTimeData = Resource::get("metrics.holder_travel_time")
            ->whereIn("holderid", array_keys($scopes))
            ->where("dateFrom", ">=", $options->dateFrom)
            ->where("dateTo", "<=", $options->dateTo)
            ->groupBy("scopeid")
            ->find("scopeid");
        $travelTime = array_column($holderTravelTimeData, "metric", "scopeid");
        return $travelTime;
    }

    /**
     * Devuelve el tiempo en ralenti por holder
     * @param $options Contiene la información necesaria para procesar la información
     * @param $holders Holders a los que tiene acceso el cliente
     * @return array
     */
    private function getRalentiTimeDataByScopeId($options, $scope) : array{
        $ralentiTimeData = [];
        $dateFromUTC = $options->dateFrom->copy()->setTimezone(DateUtils::getGMTTimeZone());
        $dateToUTC = $options->dateTo->copy()->setTimezone(DateUtils::getGMTTimeZone());
        $ralentiReports = Resource::get("report")
            ->whereIn("holderid", array_keys($scope))
            ->whereIn("eventid", [Event::END_OF_RALENTI])
            ->where("reportdate", ">", $dateFromUTC)
            ->where("reportdate", "<", $dateToUTC)
            ->limit(100000)
            ->orderByField("reportdate", "ASC")
            ->find();

        foreach ($ralentiReports as $ralentiReport) {
            if (!empty($ralentiReport->time_UNMOVING_WORKING_ENGINE)) {
                $holderRalentiTime = &$ralentiTimeData[$ralentiReport->holderid];
                $holderRalentiTime += $ralentiReport->time_UNMOVING_WORKING_ENGINE;
            }
        }

        return $ralentiTimeData;
    }

}