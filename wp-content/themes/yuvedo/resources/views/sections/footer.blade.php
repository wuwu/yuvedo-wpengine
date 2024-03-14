<footer class="content-info p-8 text-slate-700 bg-gray-300 w-full">
  <div class="flex justify-between items-center h-24 max-w-screen-2xl mx-auto">
    <div class="flex-shrink-0">
      <a class="brand" href="{{ home_url('/') }}">
        <img class="w-24 md:w-48" src="@asset('images/yuvedo_logo_footer.png')">
      </a>
    </div>
    <div class="flex flex-col items-center justify-end w-full">
      <div class="social-menu pb-4 ml-auto">
        <ul class="list-none flex">
          <li class="pr-4">
            <a href="https://twitter.com/YuvedoFound" target="_blank">
              <img class="w-auto" src="@asset('images/icons/twitter.png')">
            </a>
          </li>
          <li class="pr-4">
            <a href="https://www.instagram.com/yuvedofoundation_live" target="_blank">
              <img class="w-auto" src="@asset('images/icons/facebook.png')">
            </a>
          </li>
          <li>
            <a href="https://www.linkedin.com/company/yuvedo-foundation" target="_blank">
              <img class="w-auto" src="@asset('images/icons/linkedin.png')">
            </a>
          </li>
        </ul>
      </div>
      @php(dynamic_sidebar('footer-widget'))
      @if (has_nav_menu('footer_navigation'))
      <nav class="nav-footer ml-auto" aria-label="{{ wp_get_nav_menu_name('footer_navigation') }}">
        {!! wp_nav_menu(['theme_location' => 'footer_navigation', 'menu_class' => 'nav flex-col md:flex-row items-end', 'echo' => false]) !!}
      </nav>
      @include('partials.language-switcher')
      @endif
    </div>
  </div>
</footer>
