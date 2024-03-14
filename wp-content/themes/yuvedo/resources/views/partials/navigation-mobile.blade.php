@if ($navigation)
<nav class="flex justify-center">
  <ul class="
    flex
    flex-col
    items-end
    justify-end
    list-none
    text-xl
    bg-primary
    text-white
    rounded-lg
    py-4
    px-8
    ">
      @foreach ($navigation as $item)
        <li class="
          pr-4
          underline-offset-2
          relative
          flex
          flex-col
          justify-end
          items-end
          space-x-1
          mb-4
          {{ $item->classes ?? '' }}
        ">
        <a href="{{ $item->url }}" class="text-white hover:underline underline-offset-4">
          {{ $item->label }}
        </a>
        @if ($item->children)
        <ul
        class="
          list-none
          flex
          flex-col
          text-lg
          justify-end
          items-end
          ">
              @foreach ($item->children as $child)
                <li class="
                  my-child-item
                  items-end
                  justify-end
                  {{ $child->classes ?? '' }}
                  {{ $child->active ? 'active' : '' }}
                  ">
                  <a href="{{ $child->url }}" class="text-light02 hover:underline underline-offset-4">
                    {{ $child->label }}
                  </a>
                </li>
              @endforeach
            </ul>
            @endif
          </li>
      @endforeach
    </ul>
  </nav>
@endif
