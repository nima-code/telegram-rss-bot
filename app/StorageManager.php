<?php
namespace App;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageManager
{
    protected $chatId;
    protected $sentLinksFile;
    protected $configFile;
    protected $maxSentLinks = 500;

    public function __construct(string $chatId)
    {
        $this->chatId = $chatId;
        $this->sentLinksFile = "feeds/sent_{$chatId}.json";
        $this->configFile = "feeds/{$chatId}.json";
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

    public function loadConfig()
    {
        $config = ['feeds' => [], 'auto_send' => false];
        if (Storage::exists($this->configFile)) {
            $config = json_decode(Storage::get($this->configFile), true) ?? $config;
            if ($config === null) {
                Log::error("Invalid JSON in {$this->configFile} for chat_id: {$this->chatId}");
                $config = ['feeds' => [], 'auto_send' => false];
            }
        }
        $cleanedFeeds = [];
        foreach ($config['feeds'] as $name => $url) {
            $cleanedName = $this->normalizeFeedName($name);
            $cleanedFeeds[$cleanedName] = $url;
        }
        $config['feeds'] = $cleanedFeeds;
        Log::info("Loaded config from {$this->configFile} for chat_id: {$this->chatId}", ['config' => $config]);
        return $config;
    }

    public function saveConfig($config)
    {
        $cleanedFeeds = [];
        foreach ($config['feeds'] as $name => $url) {
            $cleanedName = $this->normalizeFeedName($name);
            $cleanedFeeds[$cleanedName] = $url;
        }
        $config['feeds'] = $cleanedFeeds;
        try {
            Storage::put($this->configFile, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            Log::info("Saved config to {$this->configFile} for chat_id: {$this->chatId}", ['config' => $config]);
        } catch (\Exception $e) {
            Log::error("Failed to save config to {$this->configFile} for chat_id: {$this->chatId}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function normalizeFeedName($name)
    {
        $cleaned = preg_replace('/[\s\t\n\r]+/u', ' ', trim($name));
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned);
        return $cleaned;
    }
}