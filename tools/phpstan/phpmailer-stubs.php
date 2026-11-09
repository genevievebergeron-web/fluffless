<?php
/**
 * Signatures de PHPMailer (wp-includes/PHPMailer), lues par PHPStan sans être chargées.
 * Seuls les membres que le module Courriel sortant touche.
 *
 * @package Fluffless
 */

// phpcs:disable

namespace PHPMailer\PHPMailer;

class PHPMailer {
	public string $Mailer      = 'mail';
	public string $Host        = 'localhost';
	public int $Port           = 25;
	public string $SMTPSecure  = '';
	public bool $SMTPAutoTLS   = true;
	public bool $SMTPAuth      = false;
	public string $Username    = '';
	public string $Password    = '';
	public int $Timeout        = 300;

	public function __construct( ?bool $exceptions = null ) {}

	public function isSMTP(): void {}
}
