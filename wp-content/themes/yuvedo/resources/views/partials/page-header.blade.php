<div class="page-header pl-8 mb-4">
  <h1 class="text-5xl text-center">{!! $title !!}</h1>
  @hasfield('sub_headline')
    <h2 class="text-3xl text-gray-500 font-thin text-center py-4">@field('sub_headline')</h2>
  @endfield
</div>
