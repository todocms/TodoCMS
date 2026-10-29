<?php
/**
 * TodoCMS 语言注册中心
 * 自动生成于 2026-10-25T20:47:42+08:00
 */
return array (
  'regex' => '/^[a-z]{2}(?:-[A-Z]{2})?$/',
  'routing' => 
  array (
    'mode' => 'path_prefix',
  ),
  'fallback_locale' => 'zh-CN',
  'whitelist' => 
  array (
    0 => 'zh-CN',
    1 => 'en-US',
  ),
  'languages' => 
  array (
    0 => 
    array (
      'mark' => 'zh',
      'locale' => 'zh-CN',
      'title' => '简体中文',
      'is_default' => true,
      'domain' => NULL,
      'path_prefix' => '',
      'enabled' => true,
      'sort_order' => 1,
      'flag' => 'cn',
      'currency_code' => 'CNY',
      'currency_exchange_rate' => 1.0,
      'currency_status' => 1,
      'is_default_currency' => 1,
      'site_ids' => 
      array (
        0 => 1,
      ),
      'default_site_id' => 1,
    ),
    1 => 
    array (
      'mark' => 'en',
      'locale' => 'en-US',
      'title' => 'English',
      'is_default' => false,
      'domain' => NULL,
      'path_prefix' => 'en',
      'enabled' => true,
      'sort_order' => 2,
      'flag' => 'us',
      'is_default_currency' => 0,
      'currency_code' => 'LAK',
      'currency_exchange_rate' => 0.15,
      'currency_status' => 1,
      'site_ids' => 
      array (
        0 => 1,
      ),
      'default_site_id' => 1,
    ),
  ),
  'domain_locale_map' => 
  array (
  ),
);