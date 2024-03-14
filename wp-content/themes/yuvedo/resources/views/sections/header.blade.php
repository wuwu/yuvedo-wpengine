<header class="fixed top-12 left-0 w-full  z-50">
  <div class="lg:mx-8  px-6 lg:px-8 opacity-90 bg-white  rounded-lg">
    <div  class="flex justify-between items-center h-16 md:h-24 ">
      <div class="logo logo-yuvedo flex-shrink-0">
          <a class="brand" href="{{ home_url('/') }}">
            <img class="w-24 md:w-48" src="@asset('images/yuvedo-foundation.png')">
          </a>
      </div>
      <div class="header-content flex items-center gap-4">
        @php(dynamic_sidebar('header-widget'))
        <button @click="toggleMenu" class="bg-none border-none">
          <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path>
          </svg>
        </button>
      </div>
    </div>
  </div>
  <div x-show="menuOpen" @click.away="menuOpen = false"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 transform scale-90"
    x-transition:enter-end="opacity-100 transform scale-100"
    x-transition:leave="transition ease-in duration-300"
    x-transition:leave-start="opacity-100 transform scale-100"
    x-transition:leave-end="opacity-0 transform scale-90"
    class="absolute top-16 right-4 z-50">
    <!-- Menu Items -->
    @if (has_nav_menu('primary_navigation'))
      @include('partials.navigation-mobile')
    @endif
  </div>
</header>
<!-- overlay -->
<div x-show="menuOpen" @click="menuOpen = false" aria-hidden="true" class="fixed inset-0 w-full h-full bg-black/50 cursor-pointer"></div>
