<?php

declare(strict_types=1);

namespace Minos\MyBB;

/**
 * The plugin's objects for one request, built once over the running forum's globals.
 *
 * `inc/plugins/minos.php`, `inc/tasks/minos.php` and `minos-webhook.php` reach every class
 * through here; the tests build it over their stand-ins with {@see build}.
 * No PHP 8 syntax.
 */
final class Plugin
{
    /** @var self|null */
    private static $instance;

    /** @var Platform */
    public $platform;

    /** @var Submitter */
    public $submitter;

    /** @var Receiver */
    public $receiver;

    /** @var Task */
    public $task;

    /** @var Installer */
    public $installer;

    /** @var Admin */
    public $admin;

    private function __construct(Platform $platform, Gateway $gateway)
    {
        $applier = new Applier($platform);
        $this->platform = $platform;
        $this->submitter = new Submitter($platform, $gateway, $applier);
        $this->receiver = new Receiver($platform, $applier);
        $this->task = new Task($platform, $this->submitter, $applier);
        $this->installer = new Installer($platform);
        $this->admin = new Admin($platform);
    }

    /**
     * The plugin over the running forum.
     *
     * @return self The one instance of this request.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(Platform::fromGlobals(), new Gateway());
        }
        return self::$instance;
    }

    /**
     * The plugin over a given adapter and transport, made the instance of this request.
     *
     * @param Platform     $platform The adapter.
     * @param Gateway|null $gateway  The gateway client (cURL by default).
     * @return self The instance.
     */
    public static function build(Platform $platform, ?Gateway $gateway = null): self
    {
        self::$instance = new self($platform, $gateway ?? new Gateway());
        return self::$instance;
    }

    /**
     * Forgets the instance (tests).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
