<?php
declare(strict_types=1);
const DATA_DIR = __DIR__ . '/data';
const GUARD = "<?php http_response_code(404); exit; ?>\n";
function read_data(string $name, array $fallback = []): array {
    $path = DATA_DIR . '/' . $name . '.php';
    if (!is_file($path)) return $fallback;
    $text = file_get_contents($path);
    if (!str_starts_with($text, GUARD)) throw new RuntimeException('Invalid saved data.');
    return json_decode(substr($text, strlen(GUARD)), true, 512, JSON_THROW_ON_ERROR);
}
function write_data(string $name, array $data): void {
    $tmp = tempnam(DATA_DIR, '.write-');
    try {
        chmod($tmp, 0600);
        if (file_put_contents($tmp, GUARD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Could not save data.');
        if (!rename($tmp, DATA_DIR . '/' . $name . '.php')) throw new RuntimeException('Could not replace data.');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
function locked(callable $fn): mixed {
    $lock = fopen(DATA_DIR . '/write.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Storage is busy.');
    try { return $fn(); } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function course(string $id = ''): array {
    $data = read_data('courses');
    $id = $id ?: ($data['active'] ?? '');
    foreach ($data['courses'] ?? [] as $c) if ($c['id'] === $id) return $c;
    throw new RuntimeException('Course not found.');
}
function web_url(string $url): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return '';
    if (isset($p['port']) && !in_array($p['port'], [80, 443], true)) return '';
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}
function absolute_url(string $url, string $base): string {
    $url = trim($url);
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) return web_url($url);
    $p = parse_url($base);
    if (str_starts_with($url, '//')) return web_url(($p['scheme'] ?? 'https') . ':' . $url);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if ($url === '') return web_url($base);
    if ($url[0] === '?') return web_url($origin . ($p['path'] ?? '/') . $url);
    $path = $url[0] === '/' ? $url : preg_replace('~/[^/]*$~', '/', $p['path'] ?? '/') . $url;
    $parts = [];
    foreach (explode('/', $path) as $part) { if ($part === '..') array_pop($parts); elseif ($part !== '.') $parts[] = $part; }
    return web_url($origin . implode('/', $parts));
}
function public_ip(string $ip): bool {
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && !str_contains($ip, ':');
}
function fetch_url(string $url): array {
    for ($redirect = 0; $redirect < 5; $redirect++) {
        if (!web_url($url)) throw new RuntimeException('Use a public http or https address.');
        $p = parse_url($url); $host = strtolower($p['host']);
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) throw new RuntimeException('Blog address could not be found.');
        foreach ($ips as $ip) if (!public_ip($ip)) throw new RuntimeException('Blog address must resolve to a public server.');
        $port = $p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80);
        $body = ''; $headers = []; $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => ["$host:$port:" . $ips[0]], CURLOPT_USERAGENT => 'CourseVoices/1.0 (educational RSS reader)',
            CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$headers) { $p = explode(':', $line, 2); if(count($p)===2) $headers[strtolower(trim($p[0]))] = trim($p[1]); return strlen($line); },
            CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$body) { if(strlen($body)+strlen($chunk)>3*1024*1024) return 0; $body.=$chunk; return strlen($chunk); }
        ]);
        $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch);
        if ($ok === false) throw new RuntimeException('Feed could not be downloaded: ' . $error);
        if ($code >= 300 && $code < 400 && isset($headers['location'])) { $url = absolute_url($headers['location'], $url); continue; }
        if ($code < 200 || $code >= 300) throw new RuntimeException('Blog returned HTTP ' . $code . '.');
        return ['body'=>$body, 'url'=>$url];
    }
    throw new RuntimeException('Too many redirects.');
}
function plain(string $html, int $limit = 260): string {
    $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
}
function image_from(string $html, string $base): string {
    if (!$html) return '';
    $doc = new DOMDocument(); @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach ($doc->getElementsByTagName('img') as $img) {
        $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
        if (preg_match('~gravatar|pixel|tracking|emoji|smilies~i', $src)) continue;
        if ($img->getAttribute('width') && (int)$img->getAttribute('width') < 80) continue;
        if ($safe = absolute_url($src, $base)) return $safe;
    }
    return '';
}
function parse_feed(string $body, string $base, array $contributor): array {
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) throw new RuntimeException('Unsupported XML declarations.');
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    if (!$xml || !in_array(strtolower($xml->getName()), ['rss','feed','rdf'], true)) throw new RuntimeException('This address did not return an RSS or Atom feed.');
    $posts = [];
    foreach ($xml->xpath('//*[local-name()="item" or local-name()="entry"]') ?: [] as $item) {
        $get = fn($name) => (string)(($item->xpath('./*[local-name()="' . $name . '"]') ?: [])[0] ?? '');
        $url = '';
        foreach ($item->xpath('./*[local-name()="link"]') ?: [] as $link) {
            if (isset($link['href'])) { if (!isset($link['rel']) || (string)$link['rel'] === 'alternate') { $url = (string)$link['href']; break; } }
            elseif ((string)$link) $url = (string)$link;
        }
        $url = absolute_url($url ?: $get('guid'), $base);
        if (!$url) continue;
        $date = $get('pubDate') ?: $get('published') ?: $get('date') ?: $get('updated');
        $timestamp = $date ? strtotime($date) : false;
        $html = $get('encoded') ?: $get('content') ?: $get('description') ?: $get('summary');
        $image = '';
        foreach ($item->xpath('.//*[local-name()="thumbnail" or local-name()="content" or local-name()="enclosure"]') ?: [] as $media) {
            if (isset($media['url']) && ($media->getName()==='thumbnail' || str_starts_with((string)$media['type'], 'image/') || (string)$media['medium']==='image')) { $image = absolute_url((string)$media['url'], $url); break; }
        }
        $posts[] = ['id'=>hash('sha256', preg_replace('/#.*$/', '', $url)), 'contributor'=>$contributor['id'], 'author'=>$contributor['name'],
            'title'=>plain($get('title'), 220) ?: 'Untitled post', 'url'=>$url, 'date'=>$timestamp ? gmdate('c', $timestamp) : null,
            'excerpt'=>plain($html), 'image'=>$image ?: image_from($html, $url)];
    }
    return $posts;
}
function discover_feed(string $url): string {
    $res = fetch_url($url);
    if (preg_match('/<(rss|feed|rdf:RDF)\b/i', $res['body'])) return $res['url'];
    $doc = new DOMDocument(); @$doc->loadHTML($res['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach ($doc->getElementsByTagName('link') as $link) {
        if (str_contains(strtolower($link->getAttribute('rel')), 'alternate') && in_array(strtolower($link->getAttribute('type')), ['application/rss+xml','application/atom+xml'], true)) {
            $href = $link->getAttribute('href');
            if (!str_contains(strtolower($href), 'comments')) return absolute_url($href, $res['url']);
        }
    }
    throw new RuntimeException('No feed was advertised. Add the RSS or Atom address in the third column.');
}
function refresh_course(string $id, bool $force = false): array {
    $c = course($id); $name = 'feed-' . $c['id'];
    $lock = fopen(DATA_DIR . '/refresh-' . $c['id'] . '.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) return ['busy'=>true];
    try {
        $cache = read_data($name);
        if (!$force && time()-($cache['attempted'] ?? 0)<900) return ['cached'=>true];
        $cache['attempted'] = time(); write_data($name, $cache);
        $posts = [];
        $allowed = array_column($c['contributors'], 'id');
        foreach ($cache['posts'] ?? [] as $post) if (in_array($post['contributor'], $allowed, true)) $posts[$post['id']] = $post;
        $statuses = [];
        foreach ($c['contributors'] as $person) {
            try {
                $feed = $person['feed'] ?: discover_feed($person['url']);
                $response = fetch_url($feed);
                $incoming = parse_feed($response['body'], $response['url'], $person);
                foreach ($incoming as $post) $posts[$post['id']] = $post;
                $statuses[$person['id']] = ['ok'=>true,'count'=>count($incoming),'checked'=>gmdate('c'),'feed'=>$response['url']];
            } catch (Throwable $e) { $statuses[$person['id']] = ['ok'=>false,'message'=>$e->getMessage(),'checked'=>gmdate('c')]; }
        }
        $posts = array_values($posts);
        usort($posts, fn($a,$b) => strcmp($b['date'] ?? '', $a['date'] ?? '') ?: strcmp($a['id'],$b['id']));
        $cache = ['attempted'=>time(),'checked'=>gmdate('c'),'posts'=>$posts,'statuses'=>$statuses];
        write_data($name, $cache);
        return ['posts'=>count($posts),'feeds'=>count($statuses),'errors'=>count(array_filter($statuses,fn($s)=>!$s['ok']))];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function public_feed(string $id): array {
    $c = course($id); $cache = read_data('feed-' . $c['id']);
    $people = array_column($c['contributors'], null, 'id');
    $posts = array_values(array_filter($cache['posts'] ?? [], function($p) use ($c,$people) {
        if (!isset($people[$p['contributor']])) return false;
        if (($c['start'] || $c['end']) && !$p['date']) return false;
        $date = $p['date'] ? substr($p['date'],0,10) : '';
        return (!$c['start'] || $date >= $c['start']) && (!$c['end'] || $date <= $c['end']);
    }));
    foreach ($posts as &$p) $p['author'] = $people[$p['contributor']]['name']; unset($p);
    return ['course'=>$c,'courses'=>array_map(fn($x)=>['id'=>$x['id'],'code'=>$x['code'],'term'=>$x['term']],read_data('courses')['courses']),
        'posts'=>$posts,'checked'=>$cache['checked'] ?? null,'stale'=>time()-($cache['attempted'] ?? 0)>=900,
        'feedErrors'=>count(array_filter($cache['statuses'] ?? [],fn($s)=>!$s['ok']))];
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function session_start_safe(): void {
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Strict']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
}
function admin_ok(): bool { return ($_SESSION['admin_until'] ?? 0) > time(); }
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) throw new RuntimeException('This page expired. Reload and try again.');
}

function import_blogs(string $text, array $existing): array {
    $rows = preg_split('/\R/', preg_replace('/^\xEF\xBB\xBF/', '', trim($text)));
    $people = []; $seen = [];
    foreach ($rows as $i=>$line) {
        if (!trim($line)) continue;
        $parts = str_getcsv($line, str_contains($line,"\t") ? "\t" : ',', '"', '');
        $parts = array_map('trim',$parts);
        if ($i === 0 && in_array(strtolower($parts[0]),['name','student','author','url','blog'],true)) continue;
        if (count($parts)===1) { $url=$parts[0]; $name=''; $feed=''; }
        else { [$name,$url]=$parts; $feed=$parts[2]??''; }
        if (!preg_match('~^https?://~i',$url)) $url='https://'.$url;
        if (!web_url($url) || ($feed && !web_url($feed))) throw new RuntimeException('Check the blog or feed address on row '.($i+1).'.');
        $key = strtolower(parse_url($url,PHP_URL_HOST)).'/'.trim(parse_url($url,PHP_URL_PATH)??'', '/').(parse_url($url,PHP_URL_QUERY)?'?'.parse_url($url,PHP_URL_QUERY):'');
        if(isset($seen[$key])) continue;
        $seen[$key]=true;
        $old = null;
        foreach ($existing as $person) if(rtrim($person['url'],'/')===rtrim($url,'/')){$old=$person;break;}
        $name = plain($name ?: ($old['name'] ?? parse_url($url,PHP_URL_HOST)),80);
        $people[]=['id'=>$old['id']??('c'.substr(hash('sha256',$key),0,14)),'name'=>$name,'url'=>$url,'feed'=>$feed];
    }
    if(count($people)>250) throw new RuntimeException('Please use at most 250 contributors per course.');
    return $people;
}
