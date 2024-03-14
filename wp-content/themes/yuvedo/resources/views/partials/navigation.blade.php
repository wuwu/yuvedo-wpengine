@if ($navigation)
<nav class="hidden lg:flex justify-center">

  <ul class="flex flex-wrap items-center list-none font-semibold text-lg">
    @foreach ($navigation as $item)
      @if ($item->children)
        <li class="pr-4 lg:pr-4 underline-offset-2 relative flex items-center space-x-1 {{ $item->classes ?? '' }} " x-data="{ open: false }" @mouseenter="open = true"
      @mouseleave="open = false">
          <a href="{{ $item->url }}" class="text-black hover:underline underline-offset-4">
            {{ $item->label }}
          </a>
      <ul
        class="list-none origin-top-right absolute top-full left-1/2 -translate-x-1/2 min-w-[240px] bg-white border border-slate-200 rounded-lg p-4 shadow-xl [&[x-cloak]]:hidden"
        x-show="open" x-transition:enter="transition ease-out duration-200 transform"
        x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-out duration-200" x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0" x-cloak
        @focusout="await $nextTick();!$el.contains($focus.focused()) && (open = false)">
            @foreach ($item->children as $child)
              <li class="my-child-item {{ $child->classes ?? '' }} {{ $child->active ? 'active' : '' }}">
                <a href="{{ $child->url }}" class="text-black hover:underline underline-offset-4">
                  {{ $child->label }}
                </a>
              </li>
            @endforeach
          </ul>
        </li>
      @else
        <li class="pr-2 lg:pr-4 {{ $item->classes ?? '' }} ">
          <a href="{{ $item->url }}" class="text-black hover:underline underline-offset-4">
            {{ $item->label }}
          </a>
        </li>
      @endif
    @endforeach
  </ul>
</nav>
@endif
