<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * A compiled watch pattern: the fixed directory to scan from and a
 * regex for the paths below it. `**` spans directories, `*` and `?`
 * stay within one path segment. Braces are expanded beforehand by
 * WatchBrace.
 *
 * @internal
 */
final readonly class WatchGlob
{
	/**
	 * @param non-empty-string $regex
	 * @param bool $deep Whether it matches paths in subdirectories of its base
	 */
	private function __construct(
		public string $base,
		public bool $deep,
		private string $regex,
	) {}

	public static function compile(string $pattern): self
	{
		$pattern = self::normalize($pattern);

		// A plain directory watches everything inside it.
		if (strcspn($pattern, '*?') === strlen($pattern) && is_dir($pattern)) {
			$pattern = rtrim($pattern, '/') . '/**';
		}

		$base = self::base($pattern);
		$rest = $base === '.' ? $pattern : substr($pattern, strlen(rtrim($base, '/')) + 1);

		return new self($base, str_contains($rest, '/') || str_contains($rest, '**'), self::regex($pattern));
	}

	/** Paths are relative to the working directory, or absolute, just like the pattern. */
	public function matches(string $path): bool
	{
		return preg_match($this->regex, $path) === 1;
	}

	private static function normalize(string $pattern): string
	{
		while (str_starts_with($pattern, './')) {
			$pattern = substr($pattern, 2);
		}

		return $pattern;
	}

	private static function base(string $pattern): string
	{
		$static = substr($pattern, 0, strcspn($pattern, '*?'));
		$slash = strrpos($static, '/');

		if ($slash === false) {
			return '.';
		}

		return $slash === 0 ? '/' : substr($static, 0, $slash);
	}

	/** @return non-empty-string */
	private static function regex(string $pattern): string
	{
		$regex = '';
		$length = strlen($pattern);

		for ($i = 0; $i < $length; $i++) {
			$char = $pattern[$i];

			if ($char === '*' && ($pattern[$i + 1] ?? '') === '*') {
				$i++;

				if (($pattern[$i + 1] ?? '') === '/') {
					// `**/` also matches no directory at all.
					$i++;
					$regex .= '(?:.*/)?';
				} else {
					$regex .= '.*';
				}

				continue;
			}

			$regex .= match ($char) {
				'*' => '[^/]*',
				'?' => '[^/]',
				default => preg_quote($char, '#'),
			};
		}

		return "#^{$regex}$#";
	}
}
