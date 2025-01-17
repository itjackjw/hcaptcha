<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace itjackjw\Hcaptcha;

use GdImage;
use Hyperf\Contract\ConfigInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

class CaptchaService implements CaptchaInterface
{
    // 验证码字符集合
    protected string $code_set = '2345678abcdefhjkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY';

    // 验证码过期时间（s）
    protected int $expired = 600;

    // 使用中文验证码
    protected bool $use_zh = false;

    // 验证码字体大小(px)
    protected int $font_size = 25;

    // 是否画混淆曲线
    protected bool $use_curve = true;

    // 是否添加杂点
    protected bool $use_noise = true;

    // 验证码图片高度
    protected int $img_height = 0;

    // 验证码图片宽度
    protected int $img_width = 0;

    // 验证码位数
    protected int $length = 5;

    // 验证码字体，不设置随机获取
    protected string $font_ttf = '';

    // 背景颜色
    protected array $background_color = [243, 251, 254];

    // 算术验证码
    protected bool $math = true;

    // 图片质量
    protected int $quality = 60;

    // 验证码图片实例
    protected false|GdImage $img;

    // 验证码字体颜色
    protected false|int $color;

    // 缓存 key
    protected string $cache_key = 'captcha:';

    /**
     * 初始化.
     */
    public function __construct(
        protected ConfigInterface $config,
        protected CacheInterface $cache
    ) {
        foreach ((array) $config->get('captcha') as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * 创建图片验证码
     * @throws InvalidArgumentException
     */
    public function create(string $key): string
    {
        // 生成 验证码
        [$text, $code] = $this->generateCode();
        // 加密 验证码
        $hash = password_hash(mb_strtolower($code, 'UTF-8'), PASSWORD_BCRYPT);
        // 保存 验证码
        $this->cache->set($this->cache_key.$key, $hash, $this->expired);

        return 'data:image/jpeg;base64,'.base64_encode($this->createImage($text));
    }

    /**
     * 生成 验证码
     */
    protected function generateCode(): array
    {
        $text = '';

        if ($this->math) {
            $this->use_zh = false;
            $this->length = 5;

            $x = rand(10, 30);
            $y = rand(1, 9);
            $text = "{$x} + {$y} = ";
            $code = $x + $y;
            $code .= '';
        } else {
            $characters = $this->use_zh
                ? preg_split('/(?<!^)(?!$)/u', $this->zhSet)
                : str_split($this->code_set);

            for ($i = 0; $i < $this->length; ++$i) {
                $text .= $characters[rand(0, count($characters) - 1)];
            }

            $code = mb_strtolower($text, 'UTF-8');
        }

        return [
            $text,
            $code,
        ];
    }

    protected function createImage(string $text): string
    {
        // 图片宽(px)
        $this->img_width || $this->img_width = (int) ($this->length * $this->font_size * 1.5 + $this->length * $this->font_size / 2);
        // 图片高(px)
        $this->img_height || $this->img_height = (int) ($this->font_size * 2.5);
        // 建立一幅 $this->imageW x $this->imageH 的图像
        $this->img = @imagecreate((int) $this->img_width, (int) $this->img_height);
        // 设置背景
        @imagecolorallocate($this->img, $this->background_color[0], $this->background_color[1], $this->background_color[2]);
        // 验证码字体随机颜色
        $this->color = @imagecolorallocate($this->img, mt_rand(1, 150), mt_rand(1, 150), mt_rand(1, 150));
        // 验证码使用随机字体
        $ttfPath = __DIR__.'/assets/ttfs/';

        if (empty($this->font_ttf)) {
            $dir = dir($ttfPath);
            $ttfs = [];
            while (false !== ($file = $dir->read())) {
                if (str_ends_with($file, '.ttf') || str_ends_with($file, '.otf')) {
                    $ttfs[] = $file;
                }
            }
            $dir->close();
            $this->font_ttf = $ttfs[array_rand($ttfs)];
        }
        // 字体
        $fontttf = $ttfPath.$this->font_ttf;

        if ($this->use_noise) {
            // 绘杂点
            $this->writeNoise();
        }
        if ($this->use_curve) {
            // 绘干扰线
            $this->writeCurve();
        }

        // 绘验证码
        foreach (str_split($text) as $index => $char) {
            $x = $this->font_size * ($index + 1);
            $y = $this->font_size + mt_rand(10, 20);
            $angle = $this->math ? 0 : mt_rand(-40, 40);

            @imagettftext($this->img, $this->font_size, $angle, (int) $x, (int) $y, (int) $this->color, $fontttf, $char);
        }

        ob_start();
        // 输出图像
        @imagejpeg($this->img, null, (int) $this->quality);
        $content = ob_get_clean();
        @imagedestroy($this->img);

        return $content;
    }

    /**
     * 画杂点
     * 往图片上写不同颜色的字母或数字.
     */
    protected function writeNoise(): void
    {
        $codeSet = '2345678abcdefhijkmnpqrstuvwxyz';
        for ($i = 0; $i < 10; ++$i) {
            // 杂点颜色
            $noiseColor = @imagecolorallocate($this->img, mt_rand(150, 225), mt_rand(150, 225), mt_rand(150, 225));
            for ($j = 0; $j < 5; ++$j) {
                // 绘杂点
                @imagestring(
                    $this->img,
                    5,
                    mt_rand(-10, $this->img_width),
                    mt_rand(-10, $this->img_height),
                    $codeSet[mt_rand(0, 29)],
                    (int) $noiseColor
                );
            }
        }
    }

    /**
     * 画一条由两条连在一起构成的随机正弦函数曲线作干扰线(你可以改成更帅的曲线函数)
     * 高中的数学公式咋都忘了涅，写出来
     * 正弦型函数解析式：y=Asin(ωx+φ)+b
     * 各常数值对函数图像的影响：
     * A：决定峰值（即纵向拉伸压缩的倍数）
     * b：表示波形在Y轴的位置关系或纵向移动距离（上加下减）
     * φ：决定波形与X轴位置关系或横向移动距离（左加右减）
     * ω：决定周期（最小正周期T=2π/∣ω∣）.
     */
    protected function writeCurve(): void
    {
        $py = 0;

        // 曲线前部分
        $A = mt_rand(1, (int) ($this->img_height / 2)); // 振幅
        $b = mt_rand((int) (-$this->img_height / 4), (int) ($this->img_height / 4)); // Y轴方向偏移量
        $f = mt_rand((int) (-$this->img_height / 4), (int) ($this->img_height / 4)); // X轴方向偏移量
        $T = mt_rand($this->img_height, $this->img_width * 2); // 周期
        $w = (2 * M_PI) / $T;

        $px1 = 0; // 曲线横坐标起始位置
        $px2 = mt_rand((int) ($this->img_width / 2), (int) ($this->img_width * 0.8)); // 曲线横坐标结束位置

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if ($w != 0) {
                $py = $A * sin($w * $px + $f) + $b + $this->img_height / 2; // y = Asin(ωx+φ) + b
                $i = (int) ($this->font_size / 5);
                while ($i > 0) {
                    @imagesetpixel(
                        $this->img,
                        (int) ($px + $i),
                        (int) ($py + $i),
                        (int) $this->color
                    );
                    --$i;
                }
            }
        }

        // 曲线后部分
        $A = mt_rand(1, (int) ($this->img_height / 2)); // 振幅
        $f = mt_rand((int) (-$this->img_height / 4), (int) ($this->img_height / 4)); // X轴方向偏移量
        $T = mt_rand($this->img_height, $this->img_width * 2); // 周期
        $w = (2 * M_PI) / $T;
        $b = $py - $A * sin($w * $px + $f) - $this->img_height / 2;
        $px1 = $px2;
        $px2 = $this->img_width;

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if ($w != 0) {
                $py = $A * sin($w * $px + $f) + $b + $this->img_height / 2; // y = Asin(ωx+φ) + b
                $i = (int) ($this->font_size / 5);
                while ($i > 0) {
                    @imagesetpixel($this->img, (int) ($px + $i), (int) ($py + $i), (int) $this->color);
                    --$i;
                }
            }
        }
    }

    /**
     * 验证图片验证码
     * @throws InvalidArgumentException
     */
    public function verify(string $key, string $code): bool
    {
        // 是否 启用验证
        if (!$this->config->get('captcha.enable', true)) {
            return true;
        }
        // 读取
        $hash = $this->cache->get($this->cache_key.$key, null);
        // 比较 验证码
        $bool = $hash && password_verify(mb_strtolower($code, 'UTF-8'), $hash);
        // 删除 验证码
        $bool && $this->cache->delete($this->cache_key.$key);

        return $bool;
    }
}
