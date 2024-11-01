<?php
/*
Plugin Name: Woocommerce Natural Sort
Plugin URI: http://www.devicesoftware.com/devblog/
Description: Sorts woocommerce products naturally 
Version: 0.0.1
Author: DeviceSoftware
Author URI: http://www.devicesoftware.com/devblog
Acknowledgement: The original UDF was provided from Drupal Natural Sort plugin  - thanks to @mooffie for sharing

Text Domain: ds-naturalsort
Domain Path: /languages/ 

* 
*/

/*  Copyright 2012  Devicesoftware  (email : info@devicesoftware.com) 

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA    

*/

define("DS_NATURALSORT_PLUGINPATH", "/" . plugin_basename( dirname(__FILE__) ));

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'Ds_Naturalsort' ) ) {
    
class Ds_Naturalsort {   
    
    protected $version = '0.0.1';
    
    protected $plugin_path;        
    
    protected $plugin_url;
    
    protected $template_url;
        
    public function plugin_url()
    {
        return $this->plugin_url;
    }
    public function plugin_path()
    {
        return $this->plugin_path;
    }
    public function list_count()
    {
        return $this->list_count;
    }

    /**
    * constructor
    * 
    */
    public function __construct() {
        
        // Define version constant
        define( 'DS_NATURALSORT_VERSION', $this->version );
        
        // plugin path
        $this->plugin_path = dirname(__FILE__);
        
        // plugin url
        $this->plugin_url = plugins_url( basename( plugin_dir_path(__FILE__) ), basename( __FILE__ ) );
        
        // Installation
        if ( is_admin() && ! defined('DOING_AJAX') ) 
        {
            register_activation_hook(__FILE__, array($this, 'install'));
            if ( get_option('ds_naturalsort_db_version') !== $this->version )
            {
                add_action( 'init', array($this, 'install'), 1 );
            }                
        }
                
        add_filter( 'posts_clauses', array($this, 'posts_clauses'), 15);
                       
    } // end constructor
    
    /**
    * modifies the query before being executes
    * 
    * @param array $clauses
    */
    public function posts_clauses($clauses)
    {
        global $wpdb, $wp_query;
                
        if(is_main_query())
        {
            if (is_tax('product_cat') || $wp_query->is_post_type_archive('product'))
            {
                $fields_array = split(",", $clauses['fields']);
                $orderbys = split(",", $clauses['orderby']);
                foreach($orderbys as $orderby)
                {
                    $order_query = split(" ", $orderby);
                    $direction = count($order_query) > 1 ? ' ' . $order_query[1] : '';
                    switch($order_query[0])
                    {
                        // title
                        case $wpdb->prefix."posts.post_title":
                            $fields_array[] = "natsort_canon(`post_title`, 'natural') as nat_canon_title";
                            $orderbys_array[] = "nat_canon_title" . $direction;
                            break;
                        case $wpdb->prefix."postmeta.meta_value":
                            $fields_array[] = "natsort_canon(`meta_value`, 'natural') as nat_canon_meta";
                            $orderbys_array[] = "nat_canon_meta" . $direction;
                            break;
                        default:
                            $orderbys_array[] = $order_query[0] . $direction;
                            break;
                    }
                }
                if(isset($orderbys_array))
                {
                    $orderbys_array = array_unique($orderbys_array);
                    $clauses['orderby'] = implode(',', $orderbys_array);                    
                }
                
                if(isset($fields_array))
                {
                    $fields_array = array_unique($fields_array);
                    $clauses['fields'] = implode(',', $fields_array);                    
                }
            }
        }        
        return $clauses;        
    } //end posts_clauses
    

    public function install()
    {
        
        //update udf
        $res = $this->udf_install();
        if(!$res)
        {
            trigger_error($this->error, E_USER_ERROR);
        }            

        // update db version 
        update_option('ds_naturalsort_db_version', $this->version );
    }

    private function udf_install() 
    {
        global $wpdb;

        $db_version = (float)$wpdb->db_version();
        
        if($db_version < 5)
        {
            $this->error = __("Later Version of MySql is required to use this plugin", "ds-naturalsort");
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $sql = "DROP FUNCTION IF EXISTS natsort_canon";
        $res = $wpdb->query($sql);
        if(!$res)
        {
            $this->error = $wpdb->last_error;
            return false;
        }
        // Main UDF
        $sql = "CREATE FUNCTION natsort_canon(s varchar(255), algorithm varchar(20)) RETURNS VARCHAR(255)
                    NO SQL
                    DETERMINISTIC
                    BEGIN
                    DECLARE orig   varchar(255)  default s;    -- the original string passed to us.
                    DECLARE ret    varchar(255)  default '';   -- the string we're to return.

                    IF s IS NULL THEN
                        RETURN NULL;

                    ELSEIF NOT s REGEXP '[0-9]' THEN
                        -- No numbers in this string, so skip the costly calculation.
                        SET ret = s;
                    ELSE

                        -- Our task is to expand all numbers to have a fixed number of digits.
                        -- 'I want 3.5 potatoes' -> 'I want [0000003500] potatoes'.

                        -- We don't have regexp replacement function in MySQL, so our first step is to
                        -- replace all numbers with '#'s. We later pull the actual values from 'orig'.

                        SET s = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(s, '0', '#'), '1', '#'), '2', '#'), '3', '#'), '4', '#');
                        SET s = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(s, '5', '#'), '6', '#'), '7', '#'), '8', '#'), '9', '#');
                        SET s = REPLACE(s, '.#', '##');    -- a decimal point may proceed a number, but it never follows it.
                        SET s = REPLACE(s, '#,#', '###');  -- numbers may contain thousands separator.
                        -- and note that we don't have to worry about the '-' in front of a number, because
                        -- its ord() is lower than that of digits.

                    BEGIN

                    DECLARE numpos int;
                    DECLARE numlen int;
                    DECLARE numstr varchar(255);

                    lp1: LOOP

                        -- find the next number
                        SET numpos = LOCATE('#', s);

                        -- no more numbers here
                        IF numpos = 0 THEN
                            SET ret = CONCAT(ret, s);
                            LEAVE lp1;
                        END IF;

                        -- take everything till the number...
                        IF algorithm = 'firstnumber' AND ret = '' THEN
                            -- however,
                            -- if it's the 'firstnumber' algorithm and no number was encountered yet,
                            -- then do nothing.
                            BEGIN END;
                        ELSE
                            SET ret = CONCAT(ret, SUBSTRING(s, 1, numpos - 1));
                        END IF;
                        -- ...and remove it from the input:
                        SET s    = SUBSTRING(s,    numpos);
                        SET orig = SUBSTRING(orig, numpos);

                        -- calculate the length of this number, which is now at the start of the string.
                        SET numlen = CHAR_LENGTH(s) - CHAR_LENGTH(TRIM(LEADING '#' FROM s));

                        -- read this number...
                        SET numstr = CAST(REPLACE(SUBSTRING(orig,1,numlen), ',', '') AS DECIMAL(13,6));
                        -- ...pad it...
                        SET numstr = LPAD(numstr, 15, '0');
                        -- ...and append it to the string we're to return.
                        SET ret = CONCAT(ret, '[', numstr, ']');

                        -- we're finished with this number, so remove it from the input:
                        SET s    = SUBSTRING(s,    numlen+1);
                        SET orig = SUBSTRING(orig, numlen+1);

                    END LOOP;
                    END;
                END IF;

                -- remove all spaces and some punctuation marks.
                SET ret = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(ret, ' ', ''), ',', ''), ':', ''), '.', ''), ';', ''), '(', ''), ')', '');

                RETURN ret;
                END
        ";
        $res = $wpdb->query($sql);
        if(!$res)
        {
            $this->error = $wpdb->last_error;
            return false;
        }
        return true;
    }
} // end class


} // end exist

global $ds_naturalsort;
// check woocommerce is an active plugin before initializing
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) 
{
    $ds_naturalsort = new Ds_Naturalsort();
    
    // localization
    load_plugin_textdomain( 'ds-naturalsort', false, DS_NATURALSORT_PLUGINPATH . '/languages' );  
}

?>