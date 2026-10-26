<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plusieurs images source envoyées au modèle image — vérifié sur la REQUÊTE
 * réellement émise, contre un faux fournisseur `php -S` (le catalogue d'outils
 * est redirigé vers lui) :
 *   - GPT Image (/v1/images/edits) : `image` pour une seule, `image[]` répété
 *     pour plusieurs — la forme du guide OpenAI « image references » ;
 *   - modèle natif (Responses API) : un `input_image` par image, en tête ;
 *   - Nano Banana (generateContent) : un `inlineData` par image, après le texte.
 * L'ordre reçu est conservé partout : c'est lui que le prompt désigne.
 */
final class ImageGeneratorSourcesTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static ?string $base = null;
    private static ?string $dir = null;

    /** @var list<string> images écrites par le générateur pendant le test */
    private array $written = [];

    protected function setUp(): void
    {
        require_once ROOT . '/src/Tools/ToolCatalog.php';
        require_once ROOT . '/src/Helpers/ImageGenerator.php';
        $base = self::server();

        $catalog = (function (): array { ToolCatalog::flushCache(); return ToolCatalog::all(); })();
        $catalog['image']['openai']['endpoints']['images_edits']       = $base . '/v1/images/edits';
        $catalog['image']['openai']['endpoints']['images_generations'] = $base . '/v1/images/generations';
        $catalog['image']['openai']['endpoints']['responses']          = $base . '/v1/responses';
        $catalog['image']['gemini']['endpoints']['generate_content']   = $base . '/gemini/{model}:generateContent';
        (new \ReflectionProperty(ToolCatalog::class, 'cache'))->setValue(null, $catalog);
        foreach (glob(self::$dir . '/req-*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function tearDown(): void
    {
        ToolCatalog::flushCache();
        foreach ($this->written as $url) {
            @unlink(ROOT . '/public' . $url);
        }
        $this->written = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
        }
        self::$server = null;
        self::$base = null;
        if (self::$dir !== null) {
            foreach (glob(self::$dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$dir);
            self::$dir = null;
        }
    }

    private static function png(): string
    {
        // Image réelle assez grande pour que la réponse de la Responses API
        // soit reconnue comme une image (ImageGenerator::looksLikeBase64Image).
        $im = imagecreatetruecolor(24, 24);
        for ($x = 0; $x < 24; $x++) {
            imagesetpixel($im, $x, $x, imagecolorallocate($im, $x * 10, 120, 255 - $x * 10));
        }
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    private static function server(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD absent.');
        }
        self::$dir = sys_get_temp_dir() . '/mia-imgsrc-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/png.b64', base64_encode(self::png()));
        file_put_contents(self::$dir . '/router.php', '<?php
$path = (string)parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
file_put_contents(__DIR__ . "/req-" . md5($path) . ".json", json_encode([
    "path" => $path,
    "content_type" => $_SERVER["CONTENT_TYPE"] ?? "",
    "body" => base64_encode((string)file_get_contents("php://input")),
]));
$png = trim(file_get_contents(__DIR__ . "/png.b64"));
header("Content-Type: application/json");
if (str_starts_with($path, "/v1/images/")) {
    echo json_encode(["data" => [["b64_json" => $png]]]);
} elseif ($path === "/v1/responses") {
    echo json_encode(["output" => [["type" => "image_generation_call", "result" => $png]]]);
} else {
    echo json_encode(["candidates" => [["content" => ["parts" => [["inlineData" => ["mimeType" => "image/png", "data" => $png]]]]]]]);
}
');
        for ($try = 0; $try < 5 && self::$server === null; $try++) {
            $port = random_int(20000, 39000);
            // Sans lecture des POST par PHP, php://input porte aussi le corps multipart.
            $proc = proc_open(
                [PHP_BINARY, '-d', 'enable_post_data_reading=0', '-S', '127.0.0.1:' . $port, self::$dir . '/router.php'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes
            );
            if (!is_resource($proc)) {
                continue;
            }
            for ($i = 0; $i < 50; $i++) {
                $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($s) {
                    fclose($s);
                    self::$server = $proc;
                    self::$base = 'http://127.0.0.1:' . $port;
                    break;
                }
                usleep(50_000);
            }
            if (self::$server === null) {
                proc_terminate($proc);
            }
        }
        if (self::$server === null) {
            self::markTestSkipped('Impossible de démarrer le faux fournisseur (php -S).');
        }
        return self::$base;
    }

    /** @return array{path:string, content_type:string, body:string} */
    private static function request(string $path): array
    {
        $file = self::$dir . '/req-' . md5($path) . '.json';
        self::assertFileExists($file, "Aucune requête reçue sur $path.");
        $req = json_decode((string)file_get_contents($file), true);
        $req['body'] = base64_decode($req['body']);
        return $req;
    }

    private function generate(string $provider, string $model, ?array $images): array
    {
        $result = ImageGenerator::generate(
            ['provider' => $provider, 'model' => $model, 'config_json' => '{}', 'api_key_enc' => Encryption::encrypt('sk-test')],
            'reprends le logo de la deuxième image',
            $images
        );
        $this->assertArrayNotHasKey('error', $result, (string)($result['error'] ?? ''));
        $this->written[] = (string)$result['image_url'];
        return $result;
    }

    /** @return list<array{b64:string, mime:string}> */
    private static function images(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            // Contenus distincts : l'ordre se lit dans la requête.
            $out[] = ['b64' => base64_encode('IMG' . $i . self::png()), 'mime' => $i === 2 ? 'image/jpeg' : 'image/png'];
        }
        return $out;
    }

    /** @return list<array{name:string, filename:string, type:string, data:string}> */
    private static function multipartFiles(string $body, string $contentType): array
    {
        preg_match('/boundary=(\S+)/', $contentType, $m);
        $parts = explode('--' . $m[1], $body);
        $files = [];
        foreach ($parts as $part) {
            if (preg_match('/name="([^"]+)"; filename="([^"]+)"\r\nContent-Type: (\S+)\r\n\r\n(.*)\r\n$/s', $part, $f) === 1) {
                $files[] = ['name' => $f[1], 'filename' => $f[2], 'type' => $f[3], 'data' => $f[4]];
            }
        }
        return $files;
    }

    public function testGptImageEnvoieImageSeulePuisImageCrochetsPourPlusieurs(): void
    {
        $this->generate('openai', 'gpt-image-2', self::images(1)[0]);
        $one = self::request('/v1/images/edits');
        $files = self::multipartFiles($one['body'], $one['content_type']);
        $this->assertSame(['image'], array_column($files, 'name'), 'Une seule image : la forme de toujours.');

        $this->generate('openai', 'gpt-image-2.5-sunburst', self::images(3));
        $many = self::request('/v1/images/edits');
        $files = self::multipartFiles($many['body'], $many['content_type']);
        $this->assertSame(['image[]', 'image[]', 'image[]'], array_column($files, 'name'));
        $this->assertSame(['source.png', 'source-2.jpg', 'source-3.png'], array_column($files, 'filename'));
        $this->assertSame(['image/png', 'image/jpeg', 'image/png'], array_column($files, 'type'));
        $this->assertSame(['IMG1', 'IMG2', 'IMG3'], array_map(static fn(array $f): string => substr($f['data'], 0, 4), $files));
        $this->assertStringContainsString('name="prompt"', $many['body']);
    }

    public function testLeModeleNatifRecoitUnInputImageParImageEnTete(): void
    {
        $this->generate('openai', 'gpt-5.4', self::images(2));
        $payload = json_decode(self::request('/v1/responses')['body'], true);
        $content = $payload['input'][0]['content'];
        $this->assertSame(['input_image', 'input_image', 'input_text'], array_column($content, 'type'));
        $this->assertStringStartsWith('data:image/png;base64,', $content[0]['image_url']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $content[1]['image_url']);
        $this->assertSame('IMG1', substr(base64_decode(substr($content[0]['image_url'], 22)), 0, 4));
        $this->assertSame('IMG2', substr(base64_decode(substr($content[1]['image_url'], 23)), 0, 4));
        $this->assertStringContainsString('dans l\'ordre où elles apparaissent', $content[2]['text']);
    }

    public function testNanoBananaRecoitUnInlineDataParImageApresLeTexte(): void
    {
        $this->generate('gemini', 'gemini-3.1-flash-image', self::images(3));
        $payload = json_decode(self::request('/gemini/gemini-3.1-flash-image:generateContent')['body'], true);
        $parts = $payload['contents'][0]['parts'];
        $this->assertArrayHasKey('text', $parts[0]);
        $this->assertSame(['image/png', 'image/jpeg', 'image/png'], array_map(static fn(array $p): string => $p['inlineData']['mimeType'], array_slice($parts, 1)));
        $this->assertSame('IMG1', substr(base64_decode($parts[1]['inlineData']['data']), 0, 4));
    }

    /** @return array<string, array{string, string, string}> */
    public static function sansImage(): array
    {
        return [
            'GPT Image'   => ['openai', 'gpt-image-2', '/v1/images/generations'],
            'natif'       => ['openai', 'gpt-5.4', '/v1/responses'],
            'Nano Banana' => ['gemini', 'gemini-3.1-flash-image', '/gemini/gemini-3.1-flash-image:generateContent'],
        ];
    }

    #[DataProvider('sansImage')]
    public function testSansImageRienNeChange(string $provider, string $model, string $path): void
    {
        $this->generate($provider, $model, null);
        $body = self::request($path)['body'];
        $this->assertStringNotContainsString('input_image', $body);
        $this->assertStringNotContainsString('inlineData', $body);
    }
}
