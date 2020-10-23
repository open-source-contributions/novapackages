<?php

namespace App\Jobs;

use App\Notifications\NotifyContributorOfInvalidPackageUrl;
use App\Tag;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Zttp\Zttp;

class CheckPackageUrls implements ShouldQueue
{

    private $package;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct($package)
    {
        $this->package = $package;
    }

    public function handle()
    {
        $urlsAreInvalid = collect([
            $this->package->url,
            $this->package->repo_url, // ToDo: is this attribute still in use? If no, we can refactor to remove this collection.
        ])->contains(function ($url) {
            try {
                return Zttp::get($url)->status() != 200;
            } catch (Exception $e) {
                return true; // If domain can't be reached, confirm URL is invalid
            }
        });
        if (!$urlsAreInvalid) return;

        $this->package->tags()->syncWithoutDetaching($this->fetchErrorTagId());

        if ($this->package->author && $this->package->authorIsUser()) {
            $this->package->author->user->notify(new NotifyContributorOfInvalidPackageUrl($this->package));
        }

        foreach ($this->package->contributors as $contributor) {
            if (!$contributor->user) return;
            $contributor->user->notify(new NotifyContributorOfInvalidPackageUrl($this->package));
        }
    }

    /**
     * Find or create 404 tag, and return tag ID
     * @return int
     */
    private function fetchErrorTagId()
    {
        $errorTag = Tag::where('name', '404 error')
            ->first();
        if ($errorTag) return $errorTag->id;

        return Tag::create([
            'name' => '404 error',
            'slug' => '404-error'
        ])->id;
    }
}
