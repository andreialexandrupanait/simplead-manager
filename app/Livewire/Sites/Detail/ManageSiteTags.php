<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\Tag;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ManageSiteTags extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public string $newTagName = '';

    public string $newTagColor = 'gray';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function allTags()
    {
        return Tag::orderBy('name')->get();
    }

    #[Computed]
    public function assignedTagIds(): array
    {
        return $this->site->tags()->pluck('tags.id')->all();
    }

    public function toggleTag(int $tagId): void
    {
        $this->authorizeSiteModification($this->site);

        $this->site->tags()->toggle($tagId);
        unset($this->assignedTagIds);
    }

    public function createTag(): void
    {
        $this->authorizeSiteModification($this->site);

        $data = $this->validate([
            'newTagName' => 'required|string|max:50|unique:tags,name',
            'newTagColor' => 'required|in:'.implode(',', Tag::COLORS),
        ]);

        $tag = Tag::create(['name' => $data['newTagName'], 'color' => $data['newTagColor']]);
        $this->site->tags()->syncWithoutDetaching([$tag->id]);

        $this->reset('newTagName');
        $this->newTagColor = 'gray';
        unset($this->allTags, $this->assignedTagIds);
    }

    public function render()
    {
        return view('livewire.sites.detail.manage-site-tags');
    }
}
