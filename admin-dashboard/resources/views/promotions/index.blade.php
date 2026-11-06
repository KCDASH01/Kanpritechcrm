@extends('layouts.admin')
@section('title', 'Coupons & Promotions — CRM Admin')
@section('page-title', 'Coupons & Promotions')
@section('header-actions')
<a href="{{ route('promotions.create') }}" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ New campaign</a>
@endsection
@section('content')
<div class="grid grid-cols-2 gap-3 md:grid-cols-4 mb-6">
 @php($discountGranted=$analytics['discount_by_currency']->isEmpty() ? '—' : $analytics['discount_by_currency']->map(fn($amount,$currency)=>$currency.' '.number_format($amount/100,2))->implode(' · '))
 @foreach ([['Campaigns',$analytics['campaigns']],['Active',$analytics['active']],['Redemptions',$analytics['redemptions']],['Discount granted',$discountGranted]] as [$label,$value])
 <div class="rounded-xl border border-gray-200 bg-white p-4"><p class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold text-gray-900">{{ $value }}</p></div>
 @endforeach
</div>
<div class="mb-4 flex flex-wrap gap-2">
 @foreach ([''=>'All','draft'=>'Draft','scheduled'=>'Scheduled','active'=>'Active','disabled'=>'Disabled','expired'=>'Expired','archived'=>'Archived'] as $key=>$label)
 <a href="{{ route('promotions.index', $key ? ['state'=>$key] : []) }}" class="rounded-full px-3 py-1.5 text-sm {{ request('state','')===$key ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-200 text-gray-600' }}">{{ $label }}</a>
 @endforeach
</div>
<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white touch-scroll">
 <table class="min-w-[900px] w-full text-sm">
  <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500"><tr><th class="p-4">Campaign</th><th>Type</th><th>Audience</th><th>Duration</th><th>State</th><th>Used</th><th class="p-4 text-right">Actions</th></tr></thead>
  <tbody class="divide-y divide-gray-100">
  @forelse ($campaigns as $campaign)
   <tr><td class="p-4"><a class="font-semibold text-indigo-700" href="{{ route('promotions.show',$campaign) }}">{{ $campaign->name }}</a><div class="text-xs text-gray-500">{{ $campaign->code ?: 'Automatic promotion' }}</div></td>
   <td>{{ $campaign->discount_type === 'percentage' ? number_format($campaign->percentage_basis_points/100,2).'%' : implode('/',$campaign->eligible_currencies??[]).' '.number_format($campaign->fixed_amount_minor/100,2) }}</td>
   <td class="capitalize">{{ str_replace('_',' ',$campaign->audience) }}</td><td class="capitalize">{{ str_replace('_',' ',$campaign->duration) }}</td>
   <td><span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium capitalize">{{ $campaign->state }}</span></td><td>{{ $campaign->successful_redemptions }}</td>
   <td class="p-4"><div class="flex justify-end gap-2"><a href="{{ in_array($campaign->state,['active','expired','archived'],true)||$campaign->redemptions_count ? route('promotions.show',$campaign) : route('promotions.edit',$campaign) }}" class="rounded-lg bg-indigo-50 px-3 py-1.5 font-medium text-indigo-700">{{ in_array($campaign->state,['active','expired','archived'],true)||$campaign->redemptions_count ? 'View' : 'Edit' }}</a><form method="POST" action="{{ route('promotions.duplicate',$campaign) }}">@csrf<button class="rounded-lg bg-gray-100 px-3 py-1.5">Duplicate</button></form></div></td></tr>
  @empty <tr><td colspan="7" class="p-12 text-center text-gray-500">No campaigns found.</td></tr> @endforelse
  </tbody>
 </table>
</div>
<div class="mt-4">{{ $campaigns->links() }}</div>
@endsection
