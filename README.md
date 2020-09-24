# PHP Panopto Upload Api

This repository implements a client library for Panopto's REST and S3 based upload API. Currently the library supports creating, deleting, and uploading files to sessions. The library assumes that the client type selected in Panopto is User Based Server Application.

## Usage

```php
$host = 'http://www.your-panopto-server-here.com';
$client_id = 'client id';
$client_secret = 'client secret';
$username = 'username';
$password = 'password';
$folder_id = 'folder id';

$client = new \PanoptoUpload\Client($host);
$client->authorize($client_id, $client_secret, $username, $password);
$session = $client->newSession($folder_id);
$session->uploadFile('manifest.xml');
$session->uploadFile('video.mp4');
$client->finishSession($session);
```

## License

Copyright University of Pittsburgh.

Freely licensed for reuse under GNU General Public
License v2 (or, at your option, any later version).
