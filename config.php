<?php
// AQUAR Fresh PHP configuration.
// Keep real secrets out of public repositories.
define('AQUAR_DB_HOST', getenv('AQUAR_DB_HOST') ?: 'localhost');
define('AQUAR_DB_NAME', getenv('AQUAR_DB_NAME') ?: 'aquar_fresh');
define('AQUAR_DB_USER', getenv('AQUAR_DB_USER') ?: 'root');
define('AQUAR_DB_PASS', getenv('AQUAR_DB_PASS') ?: '');

// Change this to a long random secret before deployment.
define('AQUAR_JWT_SECRET', getenv('AQUAR_JWT_SECRET') ?: 'rUGdvtNMIS8upXU3NJI0gzpR6dsbjIIM__9tnHguW0TguxQcBWQq3Q36sy79YnR7');

// Daraja settings. Configure these in the server environment before enabling M-Pesa.
define('DARAJA_ENV', getenv('DARAJA_ENV') ?: 'sandbox');
define('DARAJA_CONSUMER_KEY', getenv('DARAJA_CONSUMER_KEY') ?: 'xIhzAwGDk4OytEa6OS6PneLAFPFfIW1CJkxjTmdMUanXSKv8');
define('DARAJA_CONSUMER_SECRET', getenv('DARAJA_CONSUMER_SECRET') ?: 'UjPFsozPe9PYLAldpxrcAHMGviDpw3n164D7GrOAYDIa4DAdI2sOrHTUuS3GatKZ');
define('DARAJA_SHORTCODE', getenv('DARAJA_SHORTCODE') ?: '174379');
define('DARAJA_PASSKEY', getenv('DARAJA_PASSKEY') ?: 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919');
define('DARAJA_CALLBACK_URL', getenv('DARAJA_CALLBACK_URL') ?: 'https://mydomain.com/mpesa-express-simulate/');
