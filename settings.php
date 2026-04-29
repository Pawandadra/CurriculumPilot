<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_bookcourse',
        get_string('pluginname', 'local_bookcourse')
    );

    $settings->add(new admin_setting_configtext(
        'local_bookcourse/apibaseurl',
        get_string('apibaseurl', 'local_bookcourse'),
        get_string('apibaseurl_desc', 'local_bookcourse'),
        'http://localhost:8000',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_bookcourse/apitoken',
        get_string('apitoken', 'local_bookcourse'),
        get_string('apitoken_desc', 'local_bookcourse'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settings);
}

