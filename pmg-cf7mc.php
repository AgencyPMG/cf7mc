<?php
/*
Plugin Name: PMG MailChimp CF7 Integration
Plugin URI: http://pmg.co/category/wordpress
Description: Support for adding folks to mailchimp right from CF7
Version: 1.0
Author: Christopher Davis
Author URI: http://pmg.co/people/chris
License: GPL2

    Copyright 2012 Performance Media Group <seo@pmg.co>

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

PMG_CF7MC::init();

class PMG_CF7MC
{
    const OPTION = 'pmg_cf7mc_options';

    private static $ins = null;

    private $fields = array();

    public static function instance()
    {
        is_null(self::$ins) && self::$ins = new self;
        return self::$ins;
    }

    public static function init()
    {
        add_action('plugins_loaded', array(self::instance(), '_setup'));
    }

    public static function opt($key, $default='')
    {
        $opts = get_option(self::OPTION, array());
        return isset($opts[$key]) ? $opts[$key] : $default;
    }

    public function _setup()
    {
        add_action('admin_init', array($this, 'settings'));
        add_action('admin_menu', array($this, 'page'));

        if (defined('WPCF7_VERSION')) {
            add_action('init', array($this, 'cf7_init'));
        }
    }

    public function settings()
    {
        register_setting(
            self::OPTION,
            self::OPTION,
            array($this, 'validate')
        );

        add_settings_section(
            'api-keys',
            __('API Keys', 'pmg-cf7mc'),
            '__return_false',
            self::OPTION
        );

        $this->fields = array(
            'api_key'   => __('API Key', 'pmg-cf7mc'),
            'list_id'   => __('Default List ID', 'pmg-cf7mc'),
        );

        foreach($this->fields as $key => $label)
        {
            add_settings_field(
                $key,
                $label,
                array($this, 'field'),
                self::OPTION,
                'api-keys',
                array(
                    'label_for' => sprintf('%s[%s]', self::OPTION, $key),
                    'key'       => $key,
                )
            );
        }
    }

    public function validate($dirty)
    {
        $out = array();
        foreach(array_keys($this->fields) as $key)
        {
            $out[$key] = isset($dirty[$key]) ? esc_attr(strip_tags($dirty[$key])) : '';
        }
        return $out;
    }

    public function field($args)
    {
        printf(
            '<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr($args['label_for']),
            esc_attr(self::opt($args['key']))
        );
    }

    public function page()
    {
        add_submenu_page(
            'wpcf7',
            __('MailChimp Integration', 'pmg-cf7mc'),
            __('MailChimp', 'pmg-cf7mc'),
            'manage_options',
            'pmg-cf7mc',
            array($this, 'page_cb')
        );
    }

    public function page_cb()
    {
        ?>
        <div class="wrap">
            <?php screen_icon('tools'); ?>
            <h2><?php esc_html_e('MailChimp Integration', 'pmg-cf7mc'); ?></h2>
            <?php settings_errors(self::OPTION); ?>
            <form method="post" action="<?php echo admin_url('options.php'); ?>">
                <?php
                settings_fields(self::OPTION);
                do_settings_sections(self::OPTION);
                submit_button(__('Save Settings', 'pmg-cf7mc'));
                ?>
            </form>
        </div>
        <?php
    }

    public function cf7_init()
    {
        wpcf7_add_shortcode('mailchimp', array($this, 'cf7_handler'));

        add_action('admin_init', array($this, 'cf7_admin_init'));
        add_action('wpcf7_mail_sent', array($this, 'send_mailchimp'));
    }

    public function cf7_handler($tag)
    {
        if (!is_array($tag) || !self::opt('api_key')) {
            return '';
        }

        $name = isset($tag['name']) ? $tag['name'] : false;
        $options = isset($tag['options']) ? (array)$tag['options'] : array();

        // name not set in the tag, try fetchint it from options.
        if (!$name && (!$name = $this->get_name($options))) {
            return '';
        }

        $class = wpcf7_form_controls_class('checkbox');
        $id = '';
        $list_id = self::opt('list_id');
        $checked = false;

        foreach ($options as $option) {
            if(preg_match('%^id:([-0-9a-zA-Z_]+)$%', $option, $matches))
            {
                $id = $matches[1];
            }
            elseif(preg_match('%^class:([-0-9a-zA-Z_]+)$%', $option, $matches))
            {
                $class .= ' ' . $matches[1];
            }
            elseif(preg_match('%^list_id:([-0-9a-zA-Z_]+)$%', $option, $matches))
            {
                $list_id = $matches[1];
            } elseif ('checked' === $option) {
                $checked = true;
            }
        }

        // no list id bail
        if (!$list_id) {
            return '';
        }

        $atts = array();
        $atts[] = 'class="' . esc_attr($class) . '"';
        if ($id) {
            $atts[] = 'id="' . esc_attr($id) . '"';
        }

        if (wpcf7_is_posted() && !empty($_POST[$name])) {
            $checked = true;
        }

        $validation_error = wpcf7_get_validation_error($name);

        $html = sprintf(
            '<span %1$s><input type="checkbox" name="%2$s" id="%3$s" value="mc_on" %4$s /></span>',
            implode(' ', $atts),
            esc_attr($name),
            $id ? esc_attr($id) : esc_attr($name),
            $checked ? 'checked="checked"' : ''
        );

        $html = '<span class="wpcf7-form-control-wrap ' . esc_attr($name) . '">' .
            $html . $validation_error . '</span>';

        return $html;
    }

    public function cf7_admin_init()
    {
        wpcf7_add_tag_generator(
            'mailchimp',
            __('MailChimp', 'pmg-cf7mc'),
            'wpcf7-tg-pane-mc',
            array($this, 'tag_generator')
        );
    }

    public function tag_generator()
    {
        ?>
        <div id="wpcf7-tg-pane-mc" class="hidden">
            <form action="">
                <table>
                    <tr>
                        <td colspan="2"><?php esc_html_e('Name', 'pmg-cf7mc'); ?><br />
                        <input type="text" name="name" class="tg-name oneline" /></td>
                    </tr>
                    <tr>
                        <td><code>id</code> (<?php esc_html_e('optional', 'pmg-cf7mc'); ?>)<br />
                        <input type="text" name="id" class="idvalue oneline option" /></td>

                        <td><code>class</code> (<?php esc_html_e('optional', 'pmg-cf7mc'); ?>)<br />
                        <input type="text" name="class" class="classvalue oneline option" /></td>
                    </tr>
                    <tr>
                        <td><code>fname</code> (<?php esc_html_e('optional', 'pmg-cf7mc'); ?>)<br />
                        <?php esc_html_e('Where to the look for the FNAME merge variable', 'pmg-cf7mc'); ?><br />
                        <input type="text" name="fname" class="fnamevalue oneline option" /></td>
                    </tr>
                    <tr>
                        <td><code>lname</code> (<?php esc_html_e('optional', 'pmg-cf7mc'); ?>)<br />
                        <?php esc_html_e('Where to the look for the LNAME merge variable', 'pmg-cf7mc'); ?><br />
                        <input type="text" name="lname" class="lnamevalue oneline option" /></td>
                    </tr>
                    <tr>
                        <td><code>email</code> (<?php esc_html_e('optional', 'pmg-cf7mc'); ?>)<br />
                        <?php esc_html_e('Where to the look for the email for the list.', 'pmg-cf7mc'); ?><br />
                        <input type="text" name="email" class="emailvalue oneline option" /></td>
                    </tr>
                    <tr>
                        <td><code>list_id</code> (<?php esc_html_e('optional', 'pmg-cf7mc'); ?>)<br />
                        <?php esc_html_e('What list to subscribe to.', 'pmg-cf7mc'); ?><br />
                        <input type="text" name="list_id" class="list_idvalue oneline option" /></td>
                    </tr>
                    <tr>
                        <td><code>checked</code> (<?php esc_html_e('optional', 'pmg-cf7mc'); ?>)<br />
                        <?php esc_html_e('Whether the box should be check by default.', 'pmg-cf7mc'); ?><br />
                        <input type="checkbox" name="checked" class="option" /></td>
                    </tr>
                </table>

                <div class="tg-tag">
                    <?php esc_html_e("Copy this code and paste it into the form left.", 'pmg-cf7mc'); ?><br />
                    <input type="text" name="mailchimp" class="tag" readonly="readonly" onfocus="this.select()" />
                </div>
            </form>
        </div>
        <?php
    }

    public function send_mailchimp($form)
    {
        $key = self::opt('api_key');
        $_list_id = self::opt('list_id');

        if (!$key) {
            return;
        }

        $data = $form->posted_data;
        $mc_tags = wp_list_filter($form->scanned_form_tags, array('type' => 'mailchimp'));

        if (!$mc_tags) {
            return; // no mail chimp tags here
        }

        require_once(dirname(__FILE__) . '/lib/MCAPI.class.php');
        $mc = new MCAPI($key);

        foreach ($mc_tags as $mc_tag) {
            $options = isset($mc_tag['options']) ? $mc_tag['options'] : array();
            $name = !empty($mc_tag['name']) ? $mc_tag['name'] : $this->get_name($options);

            if (!$name || empty($data[$name])) {
                continue; // didn't get a name or the box wasn't checked
            }

            $ek = 'your-email';
            $list_id = false;
            $merge_vars = array();

            foreach ($options as $option) {
                if(preg_match('%^list_id:([-0-9a-zA-Z_]+)$%', $option, $matches))
                {
                    $list_id = $matches[1];
                }
                elseif(preg_match('%^(l|f)name:([-0-9a-zA-Z_]+)$%', $option, $matches))
                {
                    $k = strtoupper("{$matches[1]}NAME");
                    $merge_vars[$k] = isset($data[$matches[2]]) ? $data[$matches[2]] : '';
                }
                elseif(preg_match('%^email:([-0-9a-zA-Z_]+)$%', $option, $matches))
                {
                    $ek = $matches[1];
                }
            }

            $email = isset($data[$ek]) ? is_email($data[$ek]) : false;

            if (!$email) {
                continue;
            }

            if (!$list_id && !$_list_id) {
                continue;
            } elseif (!$list_id) {
                $list_id = $_list_id;
            }

            $r = $mc->listSubscribe($list_id, $email, $merge_vars);
        }
    }

    protected function get_name(&$options)
    {
        $rv = false;
        if(count($options) && strpos($options[0], ':') === false)
        {
            $rv = array_shift($options);
        }
        return $rv;
    }
}
