<?php
if (!defined('ABSPATH')) exit;

class APIPlatform_Templates_Module {

    public function __construct(){

        require_once __DIR__.'/helpers-templates.php';
        require_once __DIR__.'/class-builder.php';

        new APIPlatform_Template_Builder();
    }
}