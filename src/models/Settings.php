<?php

namespace daytwo\blazingcache\models;

use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    public string|bool|null $enabled = false;
    public string $cachePath = '@storage/cache/blazing-cache';
    public array $includedUriPatterns = ['.*'];
    public ?string $doApiToken = null;
    public ?string $doCdnEndpoint = null;

    /**
     * Return the resolved value for a setting which may be an env var reference
     * in the form "$VAR_NAME". If the stored value starts with "$" we will
     * attempt to read that environment variable and return its value, otherwise
     * return the stored literal.
     *
     * @param string|null $value
     * @return string|null
     */
    public function resolveEnv(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return App::parseEnv($value);
    }

    public function getEnabledBool(): bool
    {
        $parsed = App::parseBooleanEnv($this->enabled);

        return $parsed ?? false;
    }

    public function rules(): array
    {
        return [
            [['enabled'], 'safe'],
            [['cachePath'], 'string'],
            [['includedUriPatterns'], 'safe'],
            [['doApiToken', 'doCdnEndpoint'], 'safe'],
        ];
    }
}
