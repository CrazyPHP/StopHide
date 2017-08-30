# StopHide
You want to know what on the other side of shorten url? Use StopHide.

```php
require __DIR__.'/../vendor/autoload.php';

$url = 'https://goo.gl/LhFmV5';

$stph = new \CrazyPHP\StopHide\StopHide(5, 'cookie.txt', 15, 'Mozilla/5.0 (Windows NT 10.0; WOW64; rv:51.0) Gecko/20100101 Firefox/51.0');

$result = $stph->resolve($url);
var_dump($result);
```

```php
array(4) {
  ["end_url"]=>
  string(23) "https://www.amazon.com/"
  ["status"]=>
  string(5) "found"
  ["history"]=>
  array(2) { /* history */ }
  ["redirect_count"]=>
  int(2)
}
```