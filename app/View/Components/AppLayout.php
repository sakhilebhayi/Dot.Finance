<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  string|null  $title  the document title; the product name is appended. Pages that do not pass one are titled by their route.
     * @param  bool  $bare  true to render the shell without the page wrapper (for a page that lays itself out edge to edge)
     */
    public function __construct(public ?string $title = null, public bool $bare = false) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
