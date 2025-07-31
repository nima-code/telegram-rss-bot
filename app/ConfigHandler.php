<?php
namespace App;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConfigHandler
{
    protected $chatId;
    protected $config;
    protected $sentLinksFile;
    protected $maxSentLinks = 500;

    public function __construct(string $chatId)
    {
        $this->chatId = $chatId;
        $this->sentLinksFile = "feeds/sent_{$chatId}.json";
        $this->loadConfig();
        $this->initializeSentLinks();
    }

    protected function initializeSentLinks()
    {
        if (!Storage::exists($this->sentLinksFile)) {
            Storage::put($this->sentLinksFile, json_encode(['sent_links' => []], JSON_UNESCAPED_UNICODE));
            Log::info("Initialized sent links file for chat_id: {$this->chatId}", ['file' => $this->sentLinksFile]);
        }
    }

    public function loadSentLinks()
    {
        if (Storage::exists($this->sentLinksFile)) {
            $data = json_decode(Storage::get($this->sentLinksFile), true);
            $sentLinks = $data['sent_links'] ?? [];
            if (count($sentLinks) > $this->maxSentLinks) {
                $sentLinks = array_slice($sentLinks, -$this->maxSentLinks);
                Storage::put($this->sentLinksFile, json_encode(['sent_links' => $sentLinks], JSON_UNESCAPED_UNICODE));
                Log::info("Trimmed sent links to {$this->maxSentLinks} for chat_id: {$this->chatId}");
            }
            return $sentLinks;
        }
        return [];
    }

    public function saveSentLink($link)
    {
        if (!filter_var($link, FILTER_VALIDATE_URL) || $link === '#') {
            Log::warning("Invalid link not saved for chat_id: {$this->chatId}", ['link' => $link]);
            return;
        }
        $sentLinks = $this->loadSentLinks();
        if (!in_array($link, $sentLinks)) {
            try {
                $sentLinks[] = $link;
                Storage::put($this->sentLinksFile, json_encode(['sent_links' => $sentLinks], JSON_UNESCAPED_UNICODE));
                Log::info("Saved sent link for chat_id: {$this->chatId}", ['link' => $link]);
            } catch (\Exception $e) {
                Log::error("Failed to save sent link for chat_id: {$this->chatId}: {$e->getMessage()}", ['link' => $link]);
            }
        } else {
            Log::debug("Link already in sentLinks for chat_id: {$this->chatId}", ['link' => $link]);
        }
    }

    public function normalizeFeedName($name)
    {
        $cleaned = preg_replace('/[\s\t\n\r]+/u', ' ', trim($name));
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
        return $cleaned;
    }

    public function loadConfig()
    {
        $this->config = ['feeds' => [], 'auto_send' => false];
        $configFile = "feeds/{$this->chatId}.json";
        if (Storage::exists($configFile)) {
            $this->config = json_decode(Storage::get($configFile), true);
            if ($this->config === null) {
                Log::error("Invalid JSON in $configFile for chat_id: {$this->chatId}");
                $this->config = ['feeds' => [], 'auto_send' => false];
            }
        }
        $cleanedFeeds = [];
        foreach ($this->config['feeds'] as $name => $url) {
            $cleanedName = $this->normalizeFeedName($name);
            $cleanedFeeds[$cleanedName] = $url;
        }
        $this->config['feeds'] = $cleanedFeeds;
        Log::info("Loaded config from $configFile for chat_id: {$this->chatId}", ['config' => $this->config]);
    }

    public function saveConfig($config)
    {
        $cleanedFeeds = [];
        foreach ($config['feeds'] as $name => $url) {
            $cleanedName = $this->normalizeFeedName($name);
            $cleanedFeeds[$cleanedName] = $url;
        }
        $config['feeds'] = $cleanedFeeds;

        $this->config = $config;
        $configFile = "feeds/{$this->chatId}.json";
        try {
            Storage::put($configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            Log::info("Saved config to $configFile for chat_id: {$this->chatId}", ['config' => $config]);
        } catch (\Exception $e) {
            Log::error("Failed to save config to $configFile for chat_id: {$this->chatId}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getConfig()
    {
        return $this->config;
    }
}
?>