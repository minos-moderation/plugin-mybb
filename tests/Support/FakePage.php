<?php

declare(strict_types=1);

namespace Minos\MyBB\Tests\Support;

/**
 * MyBB's ACP `$page`: the module/action being loaded, the messages at the top, and the
 * calls that end a request (they throw {@see PageEnded} instead of exiting).
 */
final class FakePage
{
    /** @var string */
    public $active_module = '';

    /** @var string */
    public $active_action = '';

    /** @var array<int,array{type:string,message:string}> */
    public $extra_messages = [];

    /** @var array<int,string> */
    public $headers = [];

    /** @var array<int,array{url:string,message:string,title:string}> */
    public $confirms = [];

    public function add_breadcrumb_item($name, $url = '')
    {
    }

    public function output_header($title = '')
    {
        $this->headers[] = $title;
    }

    public function output_footer($quit = true)
    {
        throw new PageEnded('output_footer');
    }

    public function output_confirm_action($url, $message = '', $title = '')
    {
        $this->confirms[] = ['url' => $url, 'message' => $message, 'title' => $title];
        throw new PageEnded('output_confirm_action');
    }
}
