<?php declare(strict_types=1);

/**
 * Interface for processing flare predictions from the database
 */
class Database_FlarePredictionDatabase
{
    static private $db = null;

    static private function get_db()
    {
        if (self::$db === null) {
            include_once __DIR__ . "/DbConnection.php";
            self::$db = new Database_DbConnection();
        }
        return self::$db;
    }

    /**
     * Returns the latest flare predictions for the given observation time.
     *
     * @param DateTime $observationTime
     *
     * @return array
     */
    public static function getLatestFlarePredictions(DateTime $observationTime): array
    {
        $db = self::get_db();
        $date = $observationTime->format('Y-m-d H:i:s');
        // Not sure how well this query will work when the database gets large, but we'll find out in time.
        // Breakdown of query:
        // The inner join query gets the minimum time difference between the issue time and the observation time for each
        // dataset where the issue time is before the dataset and the observation time is within the prediction window.
        // The join condition filters for the predictions that meet the minimum time differences.
        $sql = sprintf("SELECT p.start_window,
                               p.end_window,
                               p.issue_time,
                               p.c,
                               p.m,
                               p.x,
                               p.cplus,
                               p.mplus,
                               p.latitude,
                               p.longitude,
                               p.hpc_x,
                               p.hpc_y,
                               dataset.name as dataset
                        FROM flare_predictions p
                        INNER JOIN
                            (SELECT dataset_id, MIN(TIMESTAMPDIFF(second, issue_time, '%s')) as dt
                            FROM flare_predictions
                            WHERE issue_time < '%s'
                                AND '%s' BETWEEN start_window AND end_window
                            GROUP BY dataset_id) g
                        ON p.dataset_id = g.dataset_id
                        AND TIMESTAMPDIFF(second, p.issue_time, '%s') = g.dt
                        LEFT JOIN flare_datasets dataset ON p.dataset_id = dataset.id",
                            $db->link->real_escape_string($date),
                            $db->link->real_escape_string($date),
                            $db->link->real_escape_string($date),
                            $db->link->real_escape_string($date));
        try {
            $result = $db->query($sql);
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Error querying flare predictions: " . $e->getMessage());
            return array();
        }
    }
}