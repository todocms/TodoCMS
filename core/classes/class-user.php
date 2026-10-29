<?php
declare(strict_types=1);

/**
 * Todo Core Infrastructure Manifest (V3 Fragmented)
 * @version 2026.10.26.02
 * @internal
 */

namespace Todo\Core;

if (!class_exists('\Todo\Core\Core_Manifest_CMS_User', false)) {
    class Core_Manifest_CMS_User
    {
        private static $meta = ['build' => '20261026_024305', 'sig' => '2e2ad3313e5c5ed3d21a6a02e99c95c7', 'lhash' => '15e67cd02b6d3e24770eb39b2327ea2d'];

        private static function calc_sig(): string
        {
            $code = file_get_contents(__FILE__);
            $code = preg_replace("/('sig'\\s*=>\\s*')[a-f0-9]{32}(')/", '$1<sig>$2', $code, 1);
            return md5($code);
        }

        private static function calc_loader_hash($path): string
        {
            if (!$path || !file_exists($path)) return '';
            $code = file_get_contents($path);
            $tokens = token_get_all($code);
            $logical = '';
            foreach ($tokens as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) continue;
                    $logical .= $token[1];
                } else {
                    $logical .= $token;
                }
            }
            return md5($logical);
        }

        private static function b64url_decode($data): string
        {
            $data = strtr($data, '-_', '+/');
            $pad = strlen($data) % 4;
            if ($pad) {
                $data .= str_repeat('=', 4 - $pad);
            }
            return base64_decode($data);
        }

        private static function is_debugger_present(): bool
        {
            return false;
            if (!extension_loaded('xdebug') && !extension_loaded('zend_debugger')) return false;

            $mode = ini_get('xdebug.mode');
            if ($mode && $mode !== 'off' && strpos($mode, 'debug') !== false) {
                if (ini_get('xdebug.start_with_request') === 'yes') {
                    return true;
                }
                if ((isset($_GET['XDEBUG_SESSION_START']) && $_GET['XDEBUG_SESSION_START'] !== '')
                    || (isset($_GET['XDEBUG_TRIGGER']) && $_GET['XDEBUG_TRIGGER'] !== '')
                    || (isset($_COOKIE['XDEBUG_SESSION']) && $_COOKIE['XDEBUG_SESSION'] !== '')
                    || (isset($_COOKIE['XDEBUG_TRIGGER']) && $_COOKIE['XDEBUG_TRIGGER'] !== '')
                    || (isset($_SERVER['XDEBUG_TRIGGER']) && $_SERVER['XDEBUG_TRIGGER'] !== '')
                    || (isset($_SERVER['PHP_IDE_CONFIG']) && $_SERVER['PHP_IDE_CONFIG'] !== '')) {
                    return true;
                }
            }
            if (function_exists('xdebug_is_debugger_active') && xdebug_is_debugger_active()) return true;
            if (extension_loaded('zend_debugger')) return true;
            return false;
        }

        private static function harden_runtime(): void
        {
            error_reporting(0);
            @ini_set('display_errors', '0');
            @ini_set('display_startup_errors', '0');
        }

        public static function boot_resource(string $k = ''): bool
        {
            self::harden_runtime();
            if (self::is_debugger_present()) {
                return false;
            }
            $sig = self::$meta['sig'] ?? '';
            if ($sig && self::calc_sig() !== $sig) {
                return false;
            }
            if(!defined('TODO_MYIONCUBE_LOADER')){
                $l = __DIR__ . '/../../core/loader.php';
                if(!file_exists($l)) $l = dirname(__DIR__, 2) . '/core/loader.php';
                if(!file_exists($l)) return false;
                $lhash = self::$meta['lhash'] ?? '';
                if ($lhash && self::calc_loader_hash($l) !== $lhash) return false;
                require_once $l;
            }
            if(class_exists('\Myioncube\Loader')){
                $blocks = array(
            'cc47a2cac0c762cce9fc29600226ae74' => array('eo0ZIaGHjzzlIAkJWEPrvwBm6maWhngFTs/bTEXKqToNeC7Eyv7Lk4HTwKyk84ANxRLznS','mRR9pq4kETe14idTfOb2ZCu/9FhXoF+P0PGZdxRqIobnIKko54q9/sRP8Zww7DIB/8RLdB','Op4DgR1X+Vez4Tl5Hl5b+KZ7iM1zf4AaFjJ9Vx4bp2SUwbo2Uz7Wxhw0eMvsWysy16YC7b','7CxfCf3GlGRE6aD+zT7+ZrUGeBkyuAcCgHkUqQdpodhpLkXkiIVtfP4u0xG9tWGnaVuEyC','9CnbOy6AWYLtipw0UUkZCTqY+CfKFo794Vr2x+rPRFCd1kUJHpcCWpVpojhEzoU='),
            'ae84c54ab92b3d09bd785550ac63209d' => array('cv1v8tKmj+/FBy2E5kHkiqDeh646eJWzJmAkF4hBUXrpr45AqK7Mkrqc3M2kDzU7A0zrs0','/i9SNRsXjwF4puYZ1ON7mLzJJmwx9yEwvMCVku9Ja+HiCm8qmSMWwCEObAhS4TVm5tSHZz','4ffrirkM9kauSp4uzCKWa8nVyov17lXsPTffLkZbRmk1VW9vRhJfSpro'),
            '037f72ea3036510907331ee550a4daad' => array('Bbv7Whp+AIpIodJNdytQV/aH1UUhGoZMg/tIUaJhUvxAvLyMIU9KkKA9PFYrATyJNmUpNm','oMZ91fx/a1QWfGfPjn7yCfxwzZGNPrIHWQNcnEKg8e7w=='),
            '75916d94b4257c354d941b9f84c109d7' => array('eq/3FJmHfrXiVVwWHNZHRAhMiCxJ5B78F3eWD+McQi4ubU6vXHmaOOLndH/47elYl7/AzR','LyPuNIlwNrQTX6OBySA1MEzvIUDHl9D+i668aaBn6yRLLQlp4w2ZQfwtz78OhbZwTzYplN','Y5PmXBsmGQmbZLI1svQYp0ZDWccThVc5VvLgJDtGP6amjl/ODrnE4LYTIteom3B+wlgFem','SGYrGHU8r+DNpqVnMSDE2N2TBPdBeldDr7OqUr9fqVb43JUwr1qfM6IDkFhppVxxYKLJXS','cBWlDeKlvrJyOWlV3lv3U3lmWyqkzBkRA6eUwk+8D9BfcxlMCaC+v+mdkuDkxNXxqWaYg9','gRFSam/kqUV3XO+flHxaPxqEKO80hirW7zNmWdKvA='),
            'b06a2f42434aafc773b375479f57b223' => array('cvs/l62HftvsdgykxI2qkUxO626k/m8XvuYosxG8hpRy2xAFup8LNZZvmd5C8A9fazEqCL','9rRxHaySRzFVJCV82h4YSMbHGAez6ObW0m5bgUkGOhtZ9gUgH6acM1jJE0BjTwlakkFpqr','66JP73SesenAkLCbh7kUxYCR2LQwPTuZIfFDrhJxbWihgUYtzYvUTX3fLzygz3woAQLiu+','KKcGJ+C+/cA0fd9Fev/8FLLDeXL+Jd32zt8ADwdcOqakg9C6BAsVkXRGAe72fr2O2ulJL8','F0VxBP2MtA2erVPQKGkkjavscWJBUjewxREY1bVxKrTVx+iYiWpzrNsrENNRGVKC1EiCec','BvWlYcHj7q0F0/bbl+hQCWncAjlQXO0kdblC01s3OxKKo0dVRJBL1i+1t+tYv4hJWJDJxd','p8Pg9tzIUwAW70ise+eRjhh9WzNWP8L28E8E7rhMeXcr6FpV0E4QU9cNveZZ6xYjD/ByI9','8MUn/+1aVM9jxBTyceo4v+vNwrfWlgVu/a/OLxpwbC1jg9TuwDMIRsNDRFN6py3KcvnfMB','DToKOcVdgXB3IHSZlVZbgIv3rldGUAY2s17tfjkSIVy0QijzU/xebOUdA0+WfwIjx+uaJs','X9DpXE7rNuVuD4gVyUzZoXQzVisdFN7KA6lU/4lRj9ucQAdzs+2gD5vAmZ3UU/Yl3Gndsj','nxs5ywoCKzDGddRHNbXS2EdBY18m2NHI60B9pYQxSQw5pph66++wVVgp4XtAiwq8oZk5j4','JLafH5Jbhy/eBPM3mjmVkOl5W4'),
            'c2a8e67aa8f3ef0c50e179996d86da8f' => array('clo/l62HftvsdgxKFA6qzIZO626kRoy+xqyxsxG8hpRyoozAOp8LV0qcOXT1nDQcAiRfGL','ZrtJjUqumoXSfXx61HwY+09+ApgsQ6yd+LIUUM9OJvMpdZDfsAGctIFNoA5gYBMeWzosib','rWKa+yMwAPbiSOBz3ECI2/43JiWz43U4LVkBjDM9cNMG6oURShgUr59pH6+NLqerVzqDiU','5Z8giLxAwGkg/UblxX9/kgcyDuPh4hTuJFSuUkC/uJ5NgVnWu9KaY5CUYskCGYWslag+s+','wl3z5KQB6P0IexB4mJFVu2rSz06C7DQj0xCxoOXOqW7kXm8RiTq5CRgwd9z9CB1fnu8Qzm','UMS2QSu0Y0QakVZlZLIonHhYSU1DA5LSFAYOfCYNzm7Acz1ccd1ahp3GwYZdBrOoMxizfk','2TWkqWCwbC9Je19c54V0B+n2GjS85rGtRqU2gV1XHJZVsdqUnsS1aFiSykMyzZAsSbsbbh','gv9Gh5xl+7vZiuW2wek2HSgq6GhvA/UA8T893Edg1SToIEEjEuYK5DuQw9i/1wc3A0DDZX','ZugFhmGzhjbSstnTWv68BF493zrBIxjmVofzTnZLWv0vx2FgaqG9x2YTSZbnc7FQhZJZwl','UCzeyBusY/4QeyyfB6dS/ftjZFeKvxwIms54UQjc3437DhIHXkA6O8'),
            '40498061d71162582f4d02296ffc0253' => array('clo/l62mj3jshyhqI4SwRaPZ6/Q1eKnDTOLxx8a8KIuY6VuGgQN8PkT8yS/gGeBMHAhjnS','S4AI8sM+gBHHE+Z3T7kqhb9fa79gQRcryXn5nMcmOt5J5zaLreKBgr4oAAETm9MeXCoiyb','rWLDcSMwAN2Usl3i3EBK/5CcJiW64Vw4L6QKaDGoXsYx1TccwN4mj6mB3dILCE6N2s07Hb','305ecG0lIGe8OsIlrAFzWyXD0FSOcMQZ+lsS1IT0iLrHJBm5qk4fumfVkaKBjSAHlKKd+f','YEgOzSu8lwUg5dpEAvEJBbqAws6/PuhWMv0yC/Cfw6zN5AV4nBFQywwjDZT6x/UT5psxdR','aQBzHeQGDNMmCzP8hw4SkdZQTL9Ibam40ZKyYUKIXQHIuo2z5+UC97iVWXcwwc5a3cyPTZ','8PAV1czBvsMu8phQhuLY/2z+P6JL6iKPiwGCkdqTOnJvJWP/k3mOknk2Bzlouu6me47Wto','xctHVebJJ5fzmFD1Uw0sSl4qm2mCSYHo6OHH+Zdg1STgMEEr8upq5DgQyYi/1wc3A0DDYG','ZugFhgD+2zFz619iaMbqDTUe/t+60sY6S9IAZuCkh8pHkUBBSpEzy8bYOQrmte2IauCgyA','m9e8oLpr5eeKtyycp6dS/ftjZFeKrwwIkHVUppTyHEprnWlR8jnQO6'),
            'f6db5dc4e196b584dd28d3057c7c2a26' => array('LFpKFEmHjy3spRjFgkJ8HsxnXAWwAIi3GnWc4JsP7zWf51Xw/A8xSkyvyWn14UjXXljVuy','a5ZXIQhnFuoo2ezTuO/gZZGIRrwEmwsoUoQNgzd+5ZuTMCI5VO+Go1B8H8SlXSyHgyNp+Z','L2KQZznFaYm369nauZqIgv1h5deI+v2ThKo/ND9EpeolmKl2PmiIIiFGNjgWlUllwZkkY9','qZgqgijcuKAgE5vv7ej8JX/wurIOheyPDtreYHWr3jRhBCdlISMeN46noJ5P1fGaYCRFYS','2mJ+BsJxxzQRzLocjMQV0YdcmR3+Pig7dRJf/vxNBhsepv+60SKd+NZVjFE8aCnfLNbfRT','2NR019bToBe1d2UbU/ugofQ51pt2NpcxiFxBcY80Xj6UzL5zLep+KxTJy+VO7eMWjh0/WB','NofFn41AhJxo+LYUJh20OY9O1UX/axUdcpePf1Hwt6oqVq0OTYsDMJG07NZhf4cHRwzE1v','9tft6Qm4L+k1e+5ILDrQBYhcBOhvIef+aeQm4iXuUAxpMnPOd+IXGJpvkYhNNEdabwmo9T','egAo0k2Jxc+j5eUO0em5gWuWEuxpl03aFHBjCB3dpbLDedGqI7q/RVJ6sZeQ1bjjqBvERS','kY/TsyItQ='),
            '7194ead313ccb280b84d5e5a3bb5d7c8' => array('LKJKFEmHfgriVQazluK9tJRcMEsRlhgyBfJi7pYTj/U5aN+8mJ87SaQKiJlJATdjTWii1J','EDZWd1tQ5p8e7Hdqqnz7fBFQauGit5E40HhDCwLdD/oGMSMw0K2qMo93vvNLvRVj9sqwq1','4e+tBIc2TmiRXT/WmIswiWkkvfC67oQ++C8E95VRZEptSN5OCtJM0n9u1n1T711WEh0pr4','PJfjKEBVKVNWdsrvRrCrBQlKBFDA1Oa/gB5pBhseWhWPn4XG5mA7pX8tr91ugIdt6Eu2+J','bRVtrC90LFN+GMcmON7kxnZmVG3iE29KuRgvF4C6YIDmtHUfRdJn/enKgcuW5iF7qk0RAy','E3rMmad2XD4bsaIYFtxtG+u5NBIgcdpFTrI01ReTXK/Ljy8BpeQCIJvywMlHOdZwbm28Zn','YWqek/JnF3AWFX8uVA1XApKbd4XXFGCIyg+tGNTj4Dd+RARYOpVgpmReqyyq374Xy83EIi','FqWwUeKyLVcnp5fYF9qNJXWyOhOVLAXdOOiNFMfmeGDKsF5/u3vSouFiv6DRCMrYfvgtaZ','SvST09F6Hw8Aw7XqwdBTHQV58Y/A4phxIa0qHLnY5f3UkoIjecMIdgwH/ZZuVVcqCA3Zmf','5f8Xql66Radcc='),
            '211522a0acc115831450741c11409510' => array('LALeFJmHflkWzldoTm0yUNBQHC43ouflxHXhWChl8POegx30iFOmtCLHwGfGIlru7CsDqR','pZKtPHbn062grlK15jZOx0jfALZebyoU4JHBErdnyGFmw5KGiLJIkJ9c+FlXRaL/3J+wLN','/QaCl/bMCfeeYYPY6vI+bzq9O7b18ugX6eLRtlImfpnRcyNLGPeJkyriPZ4B7I0ylLsSB6','c3YeqP79b46s819hJVtFHnUchXL1uLu0TK+tbeU12ZWWoGUH9/f1JdsuLVXhvb3SpX5WVV','F6Ve7KH7t+SYOeQ='),
            'f98eb073a2627ae276033a9e16e37a2d' => array('clrUFEmHj9srpULFaeXtTAtn8UO6xYil+BicJ5sP7zUdM7FheY3bS1aTfVci/7vTXhbOMW','2UlZKyRUNDH+HDgyd822KiW87wPJ5DNgqVjCpzWVn6fJwcJnWkmJfvSJnBJLFBZwCOaNnr','YQvrxY+CgDY63ubDHOyedBmCQEQ3S7OOSHOGTBPTdJPhC4jdOIaFJC8b63xFcE6nfBsAQX','3Lo57LhDpAeRHtiKze7vauKfCh1TUlcLLNPme1t78Vt9U90dcQdUti5KySAjtfKcdHQnng','DDFEkHzI6+jPAoM72B9TjxXM8LhQcRJ8X4Pvwtqcx+hu3HZUoVLtrvldLIpYPw0x2ohtNE','4WBIwdKyZFRyN9J0EPdB+3uTEymrHrmk97nwCau8n3ydlDSzgNCk3tEDfBwylzOL+bIqam','6vv7R8l38TtRDn8jHV22gQgzrxApyQqCrIY9ySu/EQ+8kDGS20Be9pZya5raOOoyYkiZg6','gr4a+qqiCoUKJOnjnyUI2KmXHFOIj1+vrZUAQGA3rBudSQNZNDsIeISx5zOZyTcpgKevaq','ULlETdHwoFOUMnqqIYOuTiZ7l2wyFrG/kfcRBEHtxDCaAShFE+SXx8rd2OM+gsHhnq0I/S','G/ki5p/CgNGG2yI/kFPOum'),
            '6cdbb30eefddff51369fe1cb3f009249' => array('eqf3FKGHfqkNg1cWMm36XGlS8w/Sd4qVyVXYKp0FH0lcHHDV2GhH1N7mQXcr3ObjiAm2xe','Hw2j/E591wfS79iKnrmbwERmtuLNV8lBXxlXEfn2nqIQ2fGu0Kqyv7Xmg9ARz5YoCbOKpA','9SO5y6YhcF5ChxkwsNYJ2gIsg8i++GDx/MVJPj+nbNkvYxgGjwXFB9XKf2Oto6r46hAFWO','RyccyovHXUNagNx3twyoi7WyFMfsLKxn1Q9rBVLpdqprJeYwDtLjEL2OcgC3GVfZOeLEaK','FVINiJWwXD5LZ8uMdg8s+DE/sJHwA+Byqq+748eQg2o='),
            '591d374a6a6f1eeeb855638e6330252d' => array('HhZKFEmHjy3sB3I6SpGcJerHGbF4NsWqhDf9vmv4J6Kzf3kW9BYbOTvMhMxAWabxNexxLS','gAKQs1ancLKe0zx+xZzA+q0KEC5zsgq9OhPDz9oVBKIAAN4nQ1Qh2Sbdx4WyyFZ2mKJDoI','21BvxvENUr2EKxiAlOp231bSX1QKuORKHrOCT0mniRmkDqO1VBNVAfBlSWqYnoFrgdap0E','zIEwBPcBf24ErCehAMbOrJLhWgXismbY3vTm5pG7ZsPJrSFnVg5Ed0rOVQnNr5p+eohZ3f','90xrI0Ok8ily2fGkTDNkP0xCjjpAsCqMrMQrSWj/8BkBpmcL/VSJIAsjmUUQtOTYVcb41X','i1MV9DW4xwxhzRDNRrtuE6fnqRqRkJPdFwjUguX7hSMj+RpBAfTzEEY/Zx+dr4xWV3Y8BU','lxWgYkJIEsQPDNnyIZObgVXgPPu1VZ0XIwnHJgqfNUC8KNi6Uuow/JwzTYBu8Gd3xKXOyW','UWnO+1Oinj7M208k3y44AKDtGSTSU123JpZa6WwPV4z5cSHAF+GWc2iE2h2aUoRyKl8iwB','dOjBRswwYf/+435oJIbtOIBMFwYwyb4XzgkQeWsdVpmRXB9lDyF/daVdQaSxxe9egJHRmF','60bn6/9E/rtyx9vfZ6Po+JVMMhJ5nKDzb3obH8CepLGzcbA1bsoX7/41+lR1HlXp7X'),
            '63519a75e8b7627591ebc6cc3fe59aa5' => array('cAI3K9KHRO/FdphhLBd24f0B0TyOiCN8+C0Ua0pE0Y4XXhXEUpCHLEKO6VqCib5nfSZLbO','mB7LfaS1csr9dRSYMU+n6BHFJTa8oJ+jE+eZ4BikfOcBnN35NafC2oLgbOHORKp7JnahZ8','A9ep/P4S9x8KWLv4qLmcqQN40PrDEDVdzkYvo0/TcWqOzCi6K55Wa16FpCWJQlK8//n4ms','9ciHrx4dE+qlgxOaERklr0lv8i5/A='),
            'a188358c6ed74b2de7abc68d6d16bf0c' => array('OTnt0LqHfrX0DoaCcVWG4OvRIrB4dJVRbVCUHXYcO4EuHgivXBbsvRPzyPRWHWl4vrNvO9','Oks6vdssS6Rvcym13k4TB0Vt5aiWJY8E9E6JjBE8oiEAwrLbJsEN/7YkYnZEluSknqDWR8','p9nOy0s+ksInEYC3zBJUpYQi23VeCPFATrAihEObQNcAy7gWAgsAkYSu+wExE6Z++A5lVE','G3U0Vgx5xVFcexLvamPEds+NBgz3sQpZNx4aNbYcF4PSyY78y4FcrJuBfNF+yK1RSitdlc','D+nikmFKKeTO2l+alr91QviI'),
            '550ae5c88f1f8ee09a2b03aac6f85f6a' => array('OTkdFLqHorXiKaDYl1r1JuXs/z6TNsXsElwla1KvGb/ADFx0juZjx4I0jfOxBzi5w6akoR','qb6gFbY5FKme56HjAn49fI8agCbnsaea5z24I6yaBhtOtmnlNizQmlubl0dSgDmSuRDi/R','Jxokksi9/WyxMGVp1PMbATDNm63a/+bJ9HZOP6iR3kDylA5gKXpqlWjpnOE0mhQVswKP15','Phap9C+bDhRhaZht52IO7cqM+REFjx3Zo+tJ9Wn47jcjosPXc+HlJY7fk28Zj2sMY8zKsi','gy09Li4MqvhAldsb/seCFBuUpg=='),
            'a9f4b2efa6d5f40c75fb44eaaa2f54f4' => array('Tvv3l62HftvsdgyyMYKTkJMn+Ztz/FIaIOjCoNJkD5ihwBbG84VTV27M9iO+8q4hL248Qn','TAuLKLsJbCK0X/d8enuzj6W4TNaUZTHypS07yiKwZjbIza2LqwxhnCUTciZhFX7glzddJk','vO+faGMcIYnfzelGk4Grq5atMO9Pd4F3gCqdN37XkXVv+Ial5VZFJa8UJk5FAVZbepI/d4','ZD3ehHVOkIxjk8knaBEzTmt+BHf1ty9eNhVYSm7qcVeOh8hXZhUP2RMxfMQlwDsz/aazvz','NnkHVgqsvTtoBN6nFfzUydQ/zEtHojbWfIY3Pygv2wX/VfzLy9opvJg2GVKu/eLTW4+UxG','IT09l7eNuZTHBYykNq3q6D6C137ayjcLr7hbu9SGpybhP/Fs4+uam6R35gaYxKrYcbnbK+','RX6LhY89hmwMLVwJuERPVf6ykdUBCWnrP94LIWYuVt9lTKrcmGpOD2mw9xw8l0qGQr/f6Z','dOEDoIg/6cz0w9JjoStEa4wpivH5Ph9Ri52rUZDWJ1NSIC1CHnnJ5NybyI2MEXL+V2Mrh5','dTUOZ7yx4oh+krQF/rsDYCH62TFjzInDsftBYhDFptOUfuf8ERPma1ZauHcdhUHys8yFoW','//iRwCLQzGPgzwFadoPanS+/mdtsKduJAjgnB8veT3b7v942SO/3Hm3BDMZd+jjmrLyYQl','KDuTJ2Chm2L4vZpzjHnGWsXzT+sqo+8wRA0/KWbkrzBbhNvb2UJOv8lgfA8/+7+YfCAAfF','VshTAdT4SP'),
            'cd2ed577843cfd2bdcf2de4d23281d7f' => array('ehm8FJmHj9aoVWPiSwu7Tyhperv2snTPl6lapJ2lw1tV/zKISN5Zvd6njzvV4FR1Gsa/zZ','Mf+v2mbdqSE/p+zMzFk9aCqD9eE3IHcTLNqMVz5wyq4RNSgNNrJSQIccN6sH8nisj6BSKH','wDWro35zlH42s4lheyVOwbowgYlk58wXBlH+nK1Md4eE/dmtMl9980c4RtNR2SDLUWws4S','r5otGrEwvPWVys1ifbJPaYHxCf6A7KlqCXwOIrg0YS9Bhtfuy0x5l+c2aWj+/lJZ7SWQLf','jsVFHDXY8WO0lyhwd06MPZ5CXKoa2B6/02wHvcQ/1g43IdNCiIdW+rw5PNo1sMLJpgPK0I','M/Par8HrnnVSW8RRv3tU3KJK63f49QmMrxDUVEUhG85gh3Fh5wK01iLsHb'),
            '88d401027d4edb8932cb6733215e18b8' => array('Kjnt0LqHfrX0Dm6EcV/ZqqF0jH3RqfsL4WlIreOUJ8QbS/dCMeuXm2F6RR+Mfp04Lb2w36','/LoeaRK/R7ppJAJeKTshXfqTeCVM01Vszxa5HMlWbq1YqVMe9ePucGl7EdkFZ9puy8uwXv','VYpMbbc7qwhF1pDnk9v+NekijzUBapXXz4zJLwFBirgAy7gU54Ala/ecisknyTE+WZ+gL3','TxIXrI8smKKtBORNJeq/3bR9URMWbYDLgsU8tzjOW2wD/v5tajQpfEB+5YNRglnt+wcXT3','nNIotoeTVE0nbZcnEiyvhaAdxnbpdNc='),
            '794022d81338e768d25c2053c4fb899b' => array('Kq/3W7qHfrXiVchk9/T1WireGH3Rqfvh4WmRrlskWnRGCLoSvi+Gd9+It+i+uz3iQbZFj5','hD71OcrqG7ieDMIa6WUzBeHBUWPrDA90T6RMCzvgVFnyToDi1bgs8yMnrsaV8erw2Rgaxo','6PJVzoO3joWAr57fo9eZFtvGbgDO0+hQ8WBdLR5O58Nq16bn4pZjrtxVin5BZ9WTOXbnA0','4XWxMTFKhcA6aP8Iy02ImYW7PhxLTpmpSTxamI/UP6RMF1iu70i39BOw54K/+63azmkz/H','ThS4UkEVfkrGypw+ogSh9NaWWN0wkC7MFeik7iuddrxO9nhho2wz1i4rkLkE8iCYVeapPZ','Qx'),
            'b97bbe4106cd35ae2b196c435846dd18' => array('ORlBFJmmjwriVZpoZaFJAZMU9GQSmK4Fpk+6fDtJsJ4ykwVIhRlpmP1Hlm4R8veH2rqiTV','5SCYumn+aq0CBiDHrBAe0acFBu+5HpxUGHjak8M9UyeKdipwl6p1dMtdJq0WpLBZocb0xs','F67ezZyR/J7dft+P/6A1N67B3bpbQKqG09F2MaFzHFdwokkZMic3+QtI8wH6WMweS54N+Y','wYqoI3o40UjfnFnqqbWThE97BfkHbqFSke+433bi/RP80cvWIaLy7WComqIqfDt+edxMtL','+LMMyOEJhuedILpfbkFvWqQbXOte0/VWw5mwroXchOhjVDIEyWzzKjYM5w7sXiYfTK4Gzm','J/PEeoGDjZtfpVTy2axfIufwVkkRS6y/xvlOddjJyhj3IIx1/eY8bsOK40EEKpTD/s3GKh','kpq3Z5SRT6k='),
            '494cb6090bda367328fc4ba8b4d97fb4' => array('Khmz0Lqmj+70CPmCjvT1WioMG65l53ELC2lIArRJZ/rDjfKRcG+hvaTbH5t89NTaU2x64X','OwE7T9J2412gf8CUD4Cb0K0IGyqfNz/fVwZymvMVW6ov4G5XX2mhaygj8/gVF7nRZ6fxP6','o9UNJcaZ0g1a45ptcuEWcw4utlSuGI44LHMHBg1FYKEx8nwYG2qD8QdzlLtr034qL2Y0tT','pVlyM845KrdgCVCpRntq+XvY6qskkehxOoZ6ju03H6oqZadEMk4JVvsvTWWlDzi+pbKq0D','AEbZinDrk+EVvkWWhyNcvChC5H4gBVBNDW0fmIZkLt2U6lSw9tVJO7eFIP1X1BBGpTXQLP','Y8WUl+BLnB32dhmFLLnibENcf79WPA1hg8H37MjQ=='),
            'bc0796539c4befd655ba4e91a2314541' => array('LGwZW7qHfgriVUtmwr5Q4OqWk+9KWSEQ1sK3wjA3A0CgtU5W2zl3gnLE2dUiuNmDWLNm3y','Qvc6Wqxsh/zfFI8sk/LyjDgyS2dwCkZebf63My38myGPzT6q7z12MEzo3mxQwR4lxNLTxn','lM+V2VrrHrBT6krP1xnaR0nMAdn4jpqDAG3aZA4x0Tx85Q2Iz0q4uuS6e5B8qF+rnyWd8d','IPE0SO+Lou/+0134WHW84uRpQwJxJqCB/5u3pZ53Efgn3PJj9PA/h+ED9HZ4zY6tBC/Tau','K2NH/gQ6m0xM7TLsSE48zl1FtyP1xtjeVh7ErPdL6nEGLAA7'),
            'f6ba7407f1aae95cb1aa23e338def36d' => array('ehn3W7qHfrXiVchk9/9fWipeGH3Rqfvh4WmRrVskU8TP8djYXBZqtGGjqmGl9yqfvgx6cx','rnM425IOK+r1GhC3DbeTkGW0ZLDHhb2PpBeJT46ckg64RReVwcMdeSj0CtS4ANjUZff8WB','FKj7BHN/WQZVTbV4tT0IjtpLZ2Yxar/5hYphJE0Z/FGa/sEo4wRf9y1OMsP3uCz0jwyvQ9','7PJCrplTZax/BvY2Pqom8qyu9SVa3DLvcYW6fgcYxhmXR/Ts4JnaCOV59GmH9lRgCSq4+3','ASkcIrCZUfIuqFrQ3MyGtbYI6f/dda2FRIHt3BwdNEEyHNlFHG/1D51VO4sMpRsLSBkfbc','XVxk8zymiryuKMbxTH'),
            '657c55cfa7f856bb9fd367a6c07be032' => array('chmzyZmmj+70CAjKIIZBReg5Hy0JKmtUe0xUUk7+TazYcPKRcKdHfL1kPG8rBAsT7L9qPe','aR91ZX/kNNQOaojUVoZoy9eBagCrgaUEfL4tQRjVUfNLHZHucsvBkzGoS6r4PS2UEQYXLE','6WtmA4W/DBtrI34td5JuFOW9DATZPiwwkeW6yKD6TXa2xtNxTI075ejM0IbxQZZ/6OugMk','2Kn3VGTI4UKckVARM7ZjQLnwNnoEpqf11vSqlzUjjRb+CCI8CbJVjQnNp5k66wx7ZKz+s2','WEoQz0fTeL+c6GM+/WkEreOuEa1YxqhTs2tCziS8fBTc5qIO30KjV2Df4zgnzvkLdojM3z','5rkZki8JQJs81jL2kQna4mPAkxUZyl3nopuw=='),
            '9cfb803dfecfb71a9d82293b1318720a' => array('YjH3lw9oj9vsEt2qf8IsJijMSA0WKPrMDxu1/17G94eg/StGRy0YvLdlSJmQQvgaUHSXYN','faJgBeT1s8DqSHYPJrw6VFQw5pqYq17wji/lkelAW2WKodC65zJsry/TR5VaPQgikegL++','4R1Rf0e3w/fymiGE0oG4n/LsbrKZqamq/q03DTqEFKqJ/THldSsjL+ohgCi1taopRAX9kM','GVl6BAdJ8sh7H7W0LFB2Rvf91Ie36p2RZrlKsaMjtzaN3uMVI6oiPEXUjXcE1RARItJEkF','+jlh2aVCPHpurUsFWmSRh5bU4yxXdWVY1px7xgDnCnNIfhcQceAKBbD3MRu16lP/9jdc5t','lnagdAZ4CpsEro0GLimKvN7pCizZhY4NNT6GOIa+QifQ0dUL4UWitm/gzc8fea4Besqap9','SSDER/XpHZX1vdug4Mbj2vDu2f6n8/br3Habey1HEcK+LQw66scSGbVDVj5Ur7sKc2LEbU','QkKJpHtB++uuxIvKJKUc9ATXQwuHSSa+BYw/WHtF2BrwGvUmBozpkkA0xrJMf/+K4hEDJR','Vf2eojkAlUBCdqzpd1BHlHHpGOEq/Sn7lvSaObUrS0lEdYnPH8qkn+ElByH6lCX3sUtXCL','WNt5xetVDhuP+B1L0p1XITxG/xV3fKB5QDQxjSBVjmvPSvVZdYxyTc3suy4F4Xr97TFbcU','UJq6Mcrq3BsjllUJvxfbIePBfVJnWangnl0sJpKR8M6anfJt93EXQecRDplSRCjT/1a6jn','6Oi8hRHjsAvQOYHdRzsqcpahb3a9EqTYej0bM8qU2SOIm6QVCG4nm3I1UMxpwEatIDMNC/','KsDyi6AP3nJcj/pAPiJBTvjSX4Xj3LDtcIeA+GkhtBDLBEIdi181ZiHLaBekjhDtnWr5y3','IgGMcTZ5gqgwsJLziwUnbd3m9N0rrvIQKIkdqNYYkE/wa8eg5Fth8j8OxmMQAj1s2sja4A','6Tk06dOe8r0PjIsWODAdlGR9eQ3NVFeksoxnZiKw+oE1I/pDkgRBq8o6I1LSyaG0lhi8AQ','74Mj+N3kr7sU54J6C4/pz4nj/TeNs='),
            '096f61c013d07fe89d640b745fa7e4a4' => array('g7v3l62HftvsdgyqMYKTkJMn+pvHJPLFPkEAz91SSq88x5JDODogdqGcI246tCMPIRhDoN','hgIg4eObU4aNog/ZrdBF4rgqiLdGMUEu9oPpDK+Z4zYHzbyWn778R+D5xrTHuQn+waLcvm','SZ8xaSg9FtifC4WdZW+kCRs1bQGP18huTBWXVugzUx9s5eU4U1krJYrKmwCmHek54Hj9EA','CTT6tPz42R7n/tVoOwjfkHMrw5iB/82xzRxc+r+tKaczjUycdDhXgR4dYjJpWxS26/WrHh','6rWy32Zf5F/RPHc+AMQWhuo99RgVawAK64idisXANvKLxq4MURC/1VVMYaX+tQeOFkQQeJ','zR6XoYgYYGo9wA1TW/CK/njzfNcFAmUib76QTbPVHv+cjF96QjTAiIsRHeP/xc0EafhcZg','JKeh0DF8nosNp65TOLeXIS49AZ4FUlNW6/YD1NH02Min7ednRBTauCohELDQmLvGyB+jSo','9vPFsv0UoHduBb3dkurfCF6asnSnJ3+sMECCNtJkQwAW5TVd1YlpTmfZXCbL/NNmB7KPLE','Y75ze/yAUWQ4C+jCOVBkJu32U7kflTHLRq+Da4JJVxreusK4B1CrNhJllSoM5idmbqR9oa','zq1WrkRVlvBh3bbHKD38lfT5urIjSiWaAOMFGOtRp1nqOP3jbBvkKFf4scKwG+XX+P1xr/','hS/VssRvhGtTeokr2qMBj/zGm0ZREk+Lemq4AdX+F1tzaIw0XlHEWFtaPV/u0ZNDRhazGV','rs5qvlLqpoodZNeRbbxf05EpnLaZ1HLtEjPJbac+VokGdbn/ZC3j27RwIo2Q/kHx7bTxKP','BTZYGSaArJmOnmAt+XdrRIs4A6o0f5c/UdR+o1K/hppilz8q/38l/x3BmboKXHG4r7t1uM','sFF96O+EZJ+VTJenIgV9VKwdXhJYy8zt6Gcy/zzdB1F1H7hUFkcfDuTa18zssOwZy+i4Y0','ulzWAn1+5gS5RQNqWI1iEeb3oM8Jp1eMqVnAMS0ukfKrI1QEyERqkw/xnYtbLG5t/v9Zv6','kb1C1xLPt4sfVtVb/RIY1KSOpZ2VRpgUk2T3g4WgCeuZAjuI/EsSs+wNiAfzE9LoBsO/xC','6NFxSPjF7o8hs7XCND2GhaE='),
            '484b060ccb06dfc7370a7ec96596935e' => array('DnZKFEmHjwGoVQGp+f6ytCQoHNGfPzdT1UQLMUQLAot8z25h9PgDWj6/fWYizzWKodrhOP','mZg7UrvBLqQvsRPrR4YPaJ0S4SZOlas818zGCmRU/eQXkw3X/z0aIfbm8MUf0dVGXrHIp5','gI7Z/OT29nMRBPxdmi5LDU01WzxApJyiYVtNIT1j216lR/XYtJ7tWxoJ5Ut4TSMCpw9cRO','YjKw9soMh5hh7vYm4KAA8U0Ih80sF5Zktwy4LVz4P0ocmRsFkF7wuLdXNoqX2Bd2qnLuOr','8HwUgdWWsyBhSCMKGh5yUzA7LN9/BFfx5Qen2mJEvcfJiuLRAkKyB7pBIPvwvjLpv8i7+4','nAB8M8EDLuPDUGLedJ9trdp0C4tQ8/cKfQLija2FM6Ndsjvmh+oVPc7g3w/2Y6Behk8dX0','r1ZiThFktbkqRurbLui5mAOaFBm73J5B9f4cwr3eTyo5DRe3dwKT9K3iTpa4WYW3Cn0LNx','wxAledNw4gXRMa54HaOZkNsTov7CLKTM5NfJdlAPuG+rWVMV8ms9ANnIVK7syb7xZmUlSW','u1FBVfB2ZvwtnaGrk9y2MUEJXNGPJjJ/e4HV2YB8bL1I5MHrvc7F0iiyK7g='),
            '1c36421edfd7608c03b2bf02d4fac1fe' => array('LK8ZFJmHfgriVTBk3LTZdJ3FCAHCvcDrxJ36yS3oM2299vnvB0iG3j9O+Mgb5QaDvT+N5+','bK78mB4WgsrG1bonc3DR/Afk+9XeEWnkdChuYnPH/L5rFPFQH+4ZjbIVdWsyLFRKEEMaVj','K9PAq+O8wpmA/BG5ExKzXyzNC38cO08NJAcH01mfzk1rxjFV5DFLWrtqpCXtGTf18eUTzI','JoVAc/2dlVRseNLG9+rkq1com2h4Qqg2a0NlKTyBVrUlLWfNMpg0z6PFRabtRCIAw8cBy+','529E/B/1etYUGMf9/GZghQ4eMiAjYRzTUhDtw7OhiotjbenTghw6OPL91cp7oBvSbaCLVp','bX/KlhjI+SQG5XOwBwIs+fureLCYiYbe3QnxaOTpKg3x/6Gdw8vKnA6/YmeLXkSBBUhUgb','Bs1zWxfebHUTxuGYbew3oiT7YvetG/RKGsRPOw8rhNTuiGnqsrxP5BrcGkw7'),
            '5db3fd952a33c1f040a4efb6244ca405' => array('OQL3W7qHZ1kNg5ik2EIC3/qjddNxVwknEICVrfqfdPoXx2iYJ4zyytTwjeIMfDKmWLBaFl','Kha9tHPW2C9DDD2ZZFpUTbnrtzbide930UaLGeIpUP0OvvcuwVzPyk6YCQnoWWyNpNzV3B','PD6+vK8YR/zJSbhge/flU0dwAIO2oN1mZjNnMtZ5XN/YNHeZWeT5oUY5sM3T+/+Dq9GrX+','r1xNafkV7rgW0XqQ55qNgJ4XnapwKuJObDkPLibyW8hZlayJUwCv2vC8E6A/IwLVrpgwOZ','1hrA'),
            'aa0fc3a5fc9d1f37c4fcdde65b920919' => array('cAIZFJmHfrX/g1cWHfV29uHpUPaBA9t3gmMg90SDXkDEg6lDz/qmyCLYRuXGLpx0kH+Wr3','BhM9S4Ab7//p3TwmfG86wPwLV67p76MjRBQFWGy55ASvjPhuwBn2CX2khrmCSf7EGnkhk3','4OwfaAJOyo4/qdFHjuE0qOd4cpegVSyigNUg+9sTBb1FTP1yCLZDukphtLci141uJ7rwjr','OqGe8nREywDzydlbKuN/waMlrTyVcu5U7H93hHJGvBzaETt0y0CvDC9g=='),
            'c99692f14c4a2b5458052b057253845f' => array('cAIZFJmHfrX/g1cWHfV29lzpUO63P9sY/Dv+9zCYkP/baVjV5IyNyEJ6EuXGLpx0Vn+Wr3','BjG9SSHhrC3a3TcUPc6qcYwo9TiJ/qZGYVSQbez8hGGvmc1uUukFscEw5lUv5KrW00qhf8','zdIfdggXvzzZsjo/LeRvpcSHVradZIZjY3iggCkzsbZawH9sMsmNR4cwfdE0/6yY7sZvYk','7D2bXa0G/iRsV7wbL7Me1b/wvqmRhvcTwbrKqf2JB3uWODhpPhEmbo'),
            '5885d030787e71043cc8d6965e71acfd' => array('cAIZW7qHfrX/g1cWHfV2SV2jflzi1v5+aSWQrapIxIfbIx8Fz/qrO3z/E+XGLpx0PX+Wrz','phyL7nCbj/4b35XE39x40c+rVi/p+nCT2Mbxna1LGfjS6d0vkGScCkd8Nmep8fPRqtOcRd','aSkdO3NSdwRpBTOykIS7oRDyq9J61GO+W83EYJWzxpN69V0uKsJCqswJJNli//7ksZtva0','jwEL96FR3kgh62YWURBbsw+NllSrkyCKnPPBMKi7zuPhmLCNpyYJD+PaooQg=='),
            '7e2be1a8993a9b1e4744dab8a96200b2' => array('cALnK9KHZ+/FzpiN5cT+BHaxxAWlAa/P3YjGs2kjOZrEZ6LhPI5ccshov2uNUr9/JcirGf','HHm7SYUcj7ZdoVX5QFht2vasikB3C3HDOsooSbmLDhU0dmvQYLyF5ZopESWaJAcnILBwLh','mnfUC7J5gWCFwyxb5IOSUDRoGBrMHBDj8sfQyHfFJbupf0PIDJGOpKtbFXB58bb+F6QTVh','stKZ58zc4Ks7iQ4peGpaL2dmRfMFoz9A=='),
            '879db336fbd3302e0ee635d142e40270' => array('cAJM8pmHfpvH3wTf6A7mBNpuN40RH6QTSXBWg5uugJPB+NQFMuAqmmsmm/pTTv0iHTB5x7','x3fTkssx95rtPvaAcKJS2bbWhfB3w98myNCZSmkSqYbiciBBnFwTPaLj6IqpdbyHx5GAXp','LDZ87MCWlp7NZLZYPILpWUccYukId4b6BMRVr8688XKVJypeee6No7e9oYzqYSBfcsUakg','1hAfbMSm7C3QBTUAvoY4+ZwhQfOae9us+qYZmj'),
            'd997c190e0b6d4eed653a84eec503f88' => array('ejlvuJmmj7X0CNsJs4B63Zqhkw0IN8cQr2nkq2nlvcWk+zLv3CRgInxU+4CLDhiLntrV0c','cYJCarrSyruKvXdJg3ZeS8zwvSsqxKge74lqt1u3yzs7WUHYFPfNGiElew79wI3WTUyUAK','kEGBFdG+0AFTzCLNDcb+FiEsVVxnFcBiB/hYsPKOtGC4N0c3g0b76kCVo/Rq9F7PsQLkmx','O8zFgOpDnKI3J3+a3socQJWzK3MiPKmo8xiYdSOskvq08+XGgbkSYpFGuDnNcWPc0UeO/G','BeFhI4o/zsC9UYaCA2p1Kvg5cBVFgHsHRzSLMsVLaFAjfiaBUhT7FxxohTSAm+csGfwMDz','jd'),
            '535edcdd09e53c4aeab5dff8a7845b67' => array('cAIZW7qHfrX/g1cWHfV2SV2jflzilfn9C7c+NZ3FdCEYHHD65IwVzb0sXOXGLpx0Vn+Wrz','phG76SH8Tk/Cbxw2TWAKYi5ZVE/57ECTNKFFGNzplIt4twiSQBy/euTZYyIp37sWUlqUp4','I5kYLFkU4ifOXH0qJCfWtwZqvtlv5nGPYZirAklUgE1ZKTk/dvJvR6aiOEk3m4mstqC91J','8b6jqevMo5+pM7V2cSAuBnZA2V9Vdx40LfHvQBE86BKd+BZBkJ9YGipg=='),
        );
                $msalt = '49727a96';
                $props = [];
                foreach (array (
  '__O0O_dce7a755' => 
  array (
  ),
  '__O0O_f32f7a4f' => 
  array (
  ),
) as $pk => $pv) {
                    $props[$pk] = is_string($pv) ? base64_encode($pv) : $pv;
                }
                return (bool)\Myioncube\Loader::dispatch_kernel('CMS_User', $blocks, 'jr/dmJUr25RJN71k5yHfvspA+1Q7kRcJIi9Q4gG4m+OWEGDBzAc/ip40ZaK3JIgG7ITSD3Kn/cCF1eoDDB1Sts2DVzNO6ZwRPXufFA1591MSfRVZtH70YvPkXiW1k3C6bA5LPgBtmSwngYZbyy5u2Kr6G9PWz+7ZrdEI2h8maLAeh43+gHjHpBm7TGfwI3bgVsLtdzmgXUf2YZK53EpqjGYyIFV6WgW8ggL1UUI2w6ua+EV/PHwtE3RIRBioGkbm7wvG5ZD5nXPENTAosk3oBGOJlzE4xbPJruvyFjrXr95DsXEqj2spb/HhyBxBpv/QWIvUpaNpofx1T85cCqlfrA==', $k ?: 'TOdO_kErNeL_e9db20f9', $msalt, $props, __FILE__, $sig);
            }
            return false;
        }
    }
}

if (defined('ABSPATH')) {
    \Todo\Core\Core_Manifest_CMS_User::boot_resource();
}
