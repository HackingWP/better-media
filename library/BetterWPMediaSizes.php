<?php

/**
 * API for media sizes CRUD
 *
 * User capabilities expected to be done prior calling this class
 *
 * @author  Martin Adamko <@martin_adamko>
 * @licence The MIT License <http://opensource.org/licenses/MIT>
 *
 */
class BetterWPMediaSizes
{
    private $option   = null;
    private $wpdb     = false;
    private static $instance = null;

    /**
     * Constructor
     *
     * @param void
     *
     */
    private function __construct()
    {
        global $wpdb;
        $this->wpdb =& $wpdb;

        $this->option = apply_filters('BetterWPMediaSizes/option', 'better_media_sizes');
    }

    /**
     * Returns singleton instance of this class
     *
     * @param       void
     * @returns     object  Instance of this class
     *
     */
    public static function instance()
    {
        if (static::$instance===null) {
            $instance = new BetterWPMediaSizes();

            return $instance;
        }

        return static::$instance;
    }

    /**
     * Return requested image size
     *
     * @param boolean $as_object (optional) If set as true, std object is retured
     * @returns array
     *
     */
    public function get_size($s, $as_object = false)
    {
        if (!is_string($s) || empty($s)) {
            return false;
        }

        $sizes = $this->get_sizes();

        if (!isset($sizes[$s])) {
            return false;
        }

        return $as_object ? (object) array_merge(array('name' => $s), $sizes[$s]) : $sizes[$s];
    }

    /**
     * Return registered image sizes
     *
     * @param boolean $array_of_objects (optional) If set as true, array of std objects are retured
     * @returns array
     *
     */
    public function get_sizes($array_of_objects = false)
    {
        $sizes = (array) get_option($this->option, array());

        if (!! $array_of_objects) {
            foreach ($sizes as $k => $size) {
                $size = array_merge(array('name' => $k), (array) $size);

                $sizes[$k] = (object) $size;
            }

            $sizes = array_values($sizes);
        }

        return $sizes;
    }

    /**
     * Process image size to determine valid input
     *
     * @param array $a Size date
     * @returns array|false     Modified array of size data or false
     *
     */
    private function process_size(array &$a)
    {
        // Name, width, height are required
        if (!
            (
            isset($a['name'])
         && isset($a['width'])
         && isset($a['height'])
            )
        ) {
            $a = false;

            return;
        }

        if (isset($a['name']) && is_string($a['name'])) {
            $a['name'] = str_replace('-', '_', sanitize_title_with_dashes(trim($a['name'])));
        }

        if (isset($a['width'])) {
            $a['width'] = (int) $a['width'];
        }

        if (isset($a['height'])) {
            $a['height'] = (int) $a['height'];
        }

        if (isset($a['crop'])) {
            $a['crop'] = !! $a['crop'];
        } else {
            $a['crop'] = false; // Default
        }

        if ($a['width'] <= 0 || $a['height'] <= 0) {
            $a = false;

            return;
        }

        return;
    }

    /**
     * Set size
     *
     * @param  array   $a   Array of size data
     * @return boolean      True on success, false on failure
     *
     */
    public function set_size(array $a)
    {
        return $this->set_sizes(array($a));
    }

    /**
     * Set array of sizes
     *
     * @param  array   $a       Array of sizes
     * @param  boolean $update  Whether is updating or setting new sizes
     * @return boolean          True on success, false on failure
     *
     */
    public function set_sizes(array $a, $update = true)
    {
        $sizes = $update ? $this->get_sizes() : array();
        $dirty = false;

        foreach ($a as $k => $size_data) {
            if (!is_numeric($k)) {
                $size_data['name'] = $k;
            }

            // Handle wron inputs
            $this->process_size($size_data);

            if (!$size_data) {
                continue;
            }

            $k =& $size_data['name'];
            unset($size_data['name']);

            $dirty = true;
            $sizes[$k] =& $size_data;
        }

        return get_option($this->option)===false ? add_option($this->option, $sizes) : update_option($this->option, $sizes);
    }

    /**
     * Delete media size
     *
     * @param   string  $k  Media size key
     * @returns boolean     True on success, false on failure
     *
     */
    public function delete_size($k)
    {
        if (!is_string($k) || empty($k)) {
            return false;
        }

        return $this->delete_sizes(array($k));
    }

    /**
     * Bulk delete more media sizes
     *
     * @param   array   $a  Media size key
     * @returns boolean     True on success, false on failure
     *
     */
    public function delete_sizes(array $a)
    {
        $sizes = $this->get_sizes();
        $dirty = false;

        foreach ($a as $k) {
            if (isset($sizes[$k])) {
                unset($sizes[$k]);
                $dirty = true;
            }
        }

        // Save when changed, fake success if there was nothing to delete
        return $dirty ? $this->set_sizes($sizes, false) : true;
    }
}
