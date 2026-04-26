<?php

namespace Lukio;

/**
 * get cities and streets data for Israel, update from data.gov.il when needed
 */
class Cities_Streets_Data
{
    const API_URL = 'https://data.gov.il/api/3/action/datastore_search';
    const CITIES_RESOURCE_ID = '5c78e9fa-c2e2-4771-93ff-7f400a12f7ba';
    const STREETS_RESOURCE_ID = '9ad3862c-8391-4b2f-84a4-2d4c68625f4b';
    const FILES_PREFIX = 'lukio_cities_streets-';

    private static $batch_size = 500;
    private static $update_data = [];

    /**
     * fetch data from the API_URL
     * 
     * @param string       $resource_id resource ID for the call
     * @param int          $offset      offset of the query call
     * @param string|array $sort        comma separated field names with ordering or an array of field names. default `''`
     * 
     * @return array|false `array` on success `false` otherwise
     * 
     * @author Itai Dotan
     */
    private static function fetch_data($resource_id, $offset, $sort = '')
    {
        $url = self::API_URL;
        $url .= '?resource_id=' . $resource_id;
        $url .= '&limit=' . self::$batch_size;
        $url .= '&offset=' . $offset;

        if (!empty($sort)) {
            if (is_array($sort)) {
                $sort = implode(',', $sort);
            }
            $url .= '&sort=' . urlencode($sort);
        }

        try {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => "Mozilla/5.0"
            ]);

            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if (!empty($error)) {
                return array('error' => $error);
            } else {
                $data = json_decode($response, true);
                if (isset($data['result'])) {
                    return $data['result'];
                }
                return false;
            }
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * update the data recursively from the URL_API
     * 
     * @param string       $name_index  index key to pull the name of the resource from
     * @param string       $resource_id resource ID for the call
     * @param string|array $sort        comma separated field names with ordering or an array of field names. default `''`
     * @param int          $offset      offset of the query call. default `0`
     * 
     * @return array `array` of data success, empty `array` otherwise
     * 
     * @author Itai Dotan
     */
    private static function update_data($name_index, $resource_id, $sort = '', $offset = 0)
    {
        if ($offset == 0) {
            // make sure using an empty array on first iteration
            self::$update_data = [];
        }

        $data = self::fetch_data($resource_id, $offset, $sort);
        if ($data == false || isset($data['error']) || !isset($data['records'])) {
            // TODO: use the error some how
            return self::$update_data;
        }

        foreach ($data['records'] as $city_data) {
            self::$update_data[] = array(
                'name' => trim($city_data[$name_index]),
                'city_num' => intval(trim($city_data['סמל_ישוב']))
            );
        }

        $next_offset = $offset + self::$batch_size;
        if ($next_offset < $data['total']) {
            return self::update_data($name_index, $resource_id, $sort, $next_offset);
        }

        return self::$update_data;
    }

    /**
     * helper function to get cities data from the API_URL
     * 
     * @return array|null `array` on success, `null` otherwise
     */
    private static function fetch_cities()
    {
        return self::update_data('שם_ישוב', self::CITIES_RESOURCE_ID, array('שם_ישוב'));
    }

    /**
     * helper function to get streets data from the API_URL
     * 
     * @return array|null `array` on success, `null` otherwise
     */
    private static function fetch_streets()
    {
        return self::update_data('שם_רחוב', self::STREETS_RESOURCE_ID, array('שם_רחוב'));
    }

    /**
     * save updated data to file
     * 
     * @param string $type      data type to update
     * @param string $file_path file to save to
     * 
     * @author Itai Dotan
     */
    private static function update_file($type, $file_path)
    {
        $function = 'fetch_' . $type;
        $data = self::$function();

        // reset to clear memory space
        self::$update_data = array();

        if ($type == 'streets') {
            // map streets to index by city_code
            $maped = [];
            foreach ($data as $street) {
                if (!isset($maped[$street['city_num']])) {
                    $maped[$street['city_num']] = array();
                }
                $maped[$street['city_num']][] = $street;
            }
            $data = $maped;
        }

        if (empty($data) && file_exists($file_path)) {
            // dont overwrite the file with empty data
            return;
        }

        file_put_contents($file_path, '<?php return ' . var_export($data, true) . ';');
    }

    /**
     * get data from file, update from
     * 
     * @param string $type         data type to update
     * @param bool   $force_update when `true` force local file update. default `false`
     * 
     * @author Itai Dotan
     */
    private static function get_data($type, $force_update = false)
    {
        $file_path = __DIR__ . '/' . self::FILES_PREFIX . $type . '.php';
        if ($force_update || !file_exists($file_path)) {
            self::update_file($type, $file_path);
        }

        return include $file_path;
    }

    /**
     * get cities data from local file, update from the URL_API when needed
     * 
     * @param bool $force_update when `true` force local file update. default `false`
     * 
     * @return array
     */
    public static function get_cities($force_update = false)
    {
        return self::get_data('cities', $force_update);
    }

    /**
     * get streets data from local file, update from the URL_API when needed
     * 
     * @param bool $force_update when `true` force local file update. default `false`
     * 
     * @return array
     */
    public static function get_streets($force_update = false)
    {
        return self::get_data('streets', $force_update);
    }
}

/*
$options = [];
$options = Cities_Streets_Data::get_cities();
$streets = Cities_Streets_Data::get_streets();
$options = $streets[9400];

echo '<select>';
foreach ($options as $option_data) {
?>
    <option value="<?php echo $option_data['name']; ?>"><?php echo $option_data['name']; ?></option>
<?php
}
echo '</select>';
*/