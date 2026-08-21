import assert from 'node:assert/strict';
import {mkdtemp,readFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';
import test from 'node:test';
import {build} from 'esbuild';
import {load} from 'cheerio';

const temporary=await mkdtemp(join(tmpdir(),'scraper4-extraction-'));
await build({entryPoints:{scraper:new URL('../worker-src/scraper.ts',import.meta.url).pathname,network:new URL('../worker-src/network.ts',import.meta.url).pathname,app:new URL('../worker-src/app.ts',import.meta.url).pathname,catalog:new URL('../worker-src/ai-catalog.ts',import.meta.url).pathname},bundle:true,format:'esm',platform:'browser',target:'es2022',outdir:temporary,entryNames:'[name]',outExtension:{'.js':'.mjs'}});
const scraper=await import(pathToFileURL(join(temporary,'scraper.mjs'))),network=await import(pathToFileURL(join(temporary,'network.mjs'))),app=await import(pathToFileURL(join(temporary,'app.mjs'))),catalog=await import(pathToFileURL(join(temporary,'catalog.mjs')));
const HTML_VOID_TAGS=new Set(['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr']);

class CheerioHTMLRewriter {
  constructor(){this.registrations=[]}
  on(selector,handler){load('<i></i>')(selector);this.registrations.push({selector,handler});return this}
  transform(response){return new Response(new ReadableStream({start:async controller=>{try{const source=await response.text(),$=load(source,{decodeEntities:true}),roots=$.root().contents().toArray();for(const root of roots)this.#walk($,root,[]);controller.enqueue(new TextEncoder().encode($.html()));controller.close()}catch(error){controller.error(error)}}}))}
  #walk($,node,active){
    if(node.type==='text'){for(const handler of active)handler.text?.({text:node.data||'',lastInTextNode:true});return}
    if(node.type==='comment')return;
    const matching=[];
    if(node.type==='tag')for(const registration of this.registrations)if($(node).is(registration.selector))matching.push(registration.handler);
    const callbacks=[],wrapper={
      tagName:node.name,getAttribute:name=>node.attribs?.[name]??null,setAttribute:(name,value)=>$(node).attr(name,value),removeAttribute:name=>$(node).removeAttr(name),
      before:(value)=>$(node).before(value),after:(value)=>$(node).after(value),remove:()=>$(node).remove(),onEndTag:callback=>{if(HTML_VOID_TAGS.has(String(node.name).toLowerCase()))throw Error('Parser error: No end tag.');callbacks.push(callback)},
      get attributes(){return Object.entries(node.attribs||{})}
    };
    for(const handler of matching)handler.element?.(wrapper);
    const scoped=[...active,...matching];for(const child of [...(node.children||[])])this.#walk($,child,scoped);
    for(const callback of callbacks.reverse())callback();
  }
}
globalThis.HTMLRewriter=CheerioHTMLRewriter;
const fixture=async name=>readFile(new URL(`./fixtures/${name}`,import.meta.url),'utf8');

test('list extraction handles nested text, Persian price, element attributes, smart links, descendants and stable dedup keys',async()=>{
  const html=await fixture('list-fa.html'),selectors={container:'li.product',title:'.woocommerce-loop-product__title, [data-title]',price:'.price, [data-price]',link:'.woocommerce-loop-product__title a, a.product-link',image:'.product-media, a.product-link',sku:'[data-sku]'};
  const products=await scraper.parseCards(html,'https://shop.example/catalog',selectors);
  assert.equal(products.length,2);assert.equal(products[0].title,'کفش پیاده روی');assert.equal(products[0].price,2_500_000);assert.equal(products[0].sku,'SKU-ATTR-1');
  assert.equal(products[0].url,'https://shop.example/product/kafsh?color=red');assert.equal(products[0].image,'https://shop.example/img/a-1200.webp');
  assert.equal(products[1].title,'کیف چرمی');assert.equal(products[1].price,950_000);assert.equal(products[1].url,'https://shop.example/product/bag');assert.equal(products[1].image,'https://shop.example/img/bag.jpg');
  const trackingChanged=html.replace('utm_source=test&color=red','utm_campaign=other&color=red'),again=await scraper.parseCards(trackingChanged,'https://shop.example/catalog',selectors);assert.equal(again[0].sourceKey,products[0].sourceKey);
});

test('Cloudflare void elements never request an end tag while extracting Tailwind product cards',async()=>{
  const html='<main><div class="flex flex-shrink flex-col"><a class="flex w-full grow" href="/product/902"><img class="aspect-[1/1.2] h-full w-full" src="/product.jpg"><div class="my-1 line-clamp-2 h-12">محصول برف</div><div class="flex flex-row items-center">۷۶۹٬۰۰۰ تومان</div></a></div></main>';
  const selectors={container:'div.flex.flex-shrink.flex-col',title:'div.my-1.line-clamp-2.h-12',price:'div.flex.flex-row.items-center',link:'a.flex.w-full.grow',image:'img.aspect-\\[1\\/1\\.2\\].h-full.w-full'};
  const products=await scraper.parseCards(html,'https://barfbox.ir/search/?page=1',selectors);
  assert.equal(products.length,1);assert.equal(products[0].title,'محصول برف');assert.equal(products[0].price,769000);assert.equal(products[0].url,'https://barfbox.ir/product/902');assert.equal(products[0].image,'https://barfbox.ir/product.jpg');
});

test('product links support data-product attributes and simple onclick navigation',async()=>{
  const html=`<main><article class="card"><h2>اول</h2><span class="go" data-product-url="/product/one?utm_source=x"></span><b class="price">۱۰۰</b></article><article class="card"><h2>دوم</h2><span class="go" data-product-link="/product/two"></span><b class="price">۲۰۰</b></article><article class="card"><h2>سوم</h2><button class="go" onclick="window.location.href='/product/three'">برو</button><b class="price">۳۰۰</b></article></main>`;
  const products=await scraper.parseCards(html,'https://shop.example/list',{container:'.card',title:'h2',price:'.price',link:'.go',image:''});
  assert.deepEqual(products.map(product=>product.url),['https://shop.example/product/one','https://shop.example/product/two','https://shop.example/product/three']);
});

test('detail extraction uses one parse, reads attribute values and gallery descendants, sanitizes HTML and groups variations',async()=>{
  const html=await fixture('detail-fa.html'),detail=await scraper.parseDetailPage(html,'https://shop.example/product/kafsh',{sku:'.summary',brand:'.brand',stock:'.stock',longDesc:'#description',gallery:'.woocommerce-product-gallery',variations:'.variations'});
  assert.equal(detail.sku,'DETAIL-123');assert.equal(detail.brand,'برند نمونه');assert.equal(detail.stock,'موجودی: 12 عدد');
  assert.ok(detail.images.includes('https://shop.example/media/full-1.jpg'));assert.ok(detail.images.includes('https://shop.example/media/pic-1600.webp'));assert.ok(detail.images.includes('https://shop.example/media/large.jpg'));
  assert.doesNotMatch(detail.longDesc,/<script|\sonclick=|javascript:/i);assert.match(detail.longDesc,/توضیح/);
  assert.ok(detail.variations.includes('red'));assert.ok(detail.variations.includes('blue'));assert.ok(detail.variations.includes('L'));assert.equal(detail.variationPrices.red,120000);assert.equal(detail.variationPrices.L,135000);
  assert.ok(detail.variationGroups.some(group=>group.name==='attribute_pa_color'&&group.values.includes('red')));assert.ok(detail.variationGroups.some(group=>group.name==='اندازه'&&group.values.includes('L')));
});

test('gallery supports newline and pipe selectors, dimensional/query dedupe, max, skip-first, tags and detail image',async()=>{
  const html=`<section class="meta"><span class="tags">کفش، ورزشی</span><div class="hero"><img data-large_image="/media/main.jpg"></div></section><div class="g1"><img src="/media/photo-300x300.jpg?size=small"></div><div class="g2"><img src="/media/photo.jpg#full"></div><div class="g3"><a href="/media/second.webp"><img src="/media/second-150x150.webp"></a></div><div class="g4"><img src="/media/third.png"></div>`;
  const selector='.g1 img\n.g2 img|.g3\n.g4 img';
  const limited=await scraper.parseDetailPage(html,'https://shop.example/product/x',{tags:'.tags',detailImage:'.hero',gallery:selector,galleryMax:2});
  assert.equal(limited.tags,'کفش، ورزشی');assert.equal(limited.mainImage,'https://shop.example/media/main.jpg');
  assert.deepEqual(limited.images,['https://shop.example/media/photo-300x300.jpg?size=small','https://shop.example/media/second.webp']);
  const skipped=await scraper.parseDetailPage(html,'https://shop.example/product/x',{gallery:selector,galleryMax:2,gallerySkipFirst:true});
  assert.deepEqual(skipped.images,['https://shop.example/media/second.webp']);
});

test('PHP profile normalization keeps list and detail image selectors separate and maps every gallery mode',()=>{
  const base={id:'php-profile',name:'نمونه',url:'https://shop.example/list',selectors:{container:'.card',title:'h2',price:'.price',link:'a',image:'.list-image'},detailSelectors:{shortDesc:{enabled:true,selector:'.summary'},image:{enabled:true,selector:'.hero'},tags:{enabled:true,selector:'.tags'},brand:{enabled:false,selector:'.disabled'}}};
  const manual=app.normalizeProfile({...base,gallery:{mode:'manual',selectors:'.a img\n.b img',max:2,skip_first:true}});
  assert.equal(manual.selectors.image,'.list-image');assert.equal(manual.selectors.detailImage,'.hero');assert.equal(manual.selectors.shortDesc,'.summary');assert.equal(manual.selectors.tags,'.tags');assert.equal(manual.selectors.brand,undefined);assert.equal(manual.selectors.gallery,'.a img\n.b img');assert.equal(manual.selectors.galleryMax,2);assert.equal(manual.selectors.gallerySkipFirst,true);
  const auto=app.normalizeProfile({...base,gallery:{mode:'auto',box:'.gallery',max:99}});assert.equal(auto.selectors.gallery,'.gallery');assert.equal(auto.selectors.galleryMax,30);
  const numbered=app.normalizeProfile({...base,gallery:{mode:'number',pattern:'.slide-{n} img',from:1,to:3,max:10}});assert.equal(numbered.selectors.gallery,'.slide-1 img\n.slide-2 img\n.slide-3 img');
  const off=app.normalizeProfile({...base,gallery:{mode:'off'}});assert.equal(off.selectors.gallery,'');
  const stringFlag=app.normalizeProfile({...base,selectors:{...base.selectors,gallerySkipFirst:'false'}});assert.equal(stringFlag.selectors.gallerySkipFirst,false);
  assert.throws(()=>app.normalizeProfile({...base,selectors:{...base.selectors,container:''}}),/container/);
});

test('JSON-LD completes partially rendered cards and provides detail variants without overriding gallery off',async()=>{
  const html=`<article class="card"><a href="/p/red"><h2>عطر قرمز</h2></a></article><script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"عطر قرمز","url":"https://shop.example/p/red","image":["/img/main.jpg","/img/second.jpg"],"sku":"JSON-1","description":"توضیح ساختاریافته","offers":{"price":"۱۲۵۰۰۰","availability":"https://schema.org/OutOfStock"},"keywords":["عطر","قرمز"],"hasVariant":[{"@type":"Product","name":"قرمز 50 میل","color":"قرمز","size":"50 میل","image":"/img/variant.jpg","offers":{"price":"۱۳۵۰۰۰"}}]}</script>`;
  const cards=await scraper.parseCards(html,'https://shop.example/list',{container:'.card',title:'h2',link:'a',price:'.missing',image:'.missing'});
  assert.equal(cards.length,1);assert.equal(cards[0].price,125000);assert.equal(cards[0].image,'https://shop.example/img/main.jpg');assert.equal(cards[0].sku,'JSON-1');assert.equal(cards[0].shortDesc,'توضیح ساختاریافته');assert.equal(cards[0].stock,0);
  const withoutDomLink=await scraper.parseCards(html.replace('<a href="/p/red">','<div>').replace('</a>','</div>'),'https://shop.example/list',{container:'.card',title:'h2',link:'.missing',price:'.missing',image:'.missing'});assert.equal(withoutDomLink.length,1);assert.equal(withoutDomLink[0].url,'https://shop.example/p/red');assert.equal(withoutDomLink[0].sku,'JSON-1');
  const off=await scraper.parseDetailPage(html,'https://shop.example/p/red',{gallery:''});assert.equal(off.mainImage,'https://shop.example/img/main.jpg');assert.deepEqual(off.images,[]);assert.ok(off.variations.includes('قرمز'));assert.ok(off.variations.includes('50 میل'));assert.equal(off.variationPrices['قرمز'],135000);
  const skipped=await scraper.parseDetailPage('<div class="gallery"><img src="/only.jpg"></div>','https://shop.example/p/red',{gallery:'.gallery',gallerySkipFirst:true});assert.deepEqual(skipped.images,[]);
});

test('pagination URL construction matches every PHP mode and drops stale path query strings',()=>{
  const profile=(pagination,paginationValue,url='https://shop.test/catalog?sort=asc#items')=>({url,pagination,paginationValue});
  assert.equal(scraper.pageUrl(profile('query_page','wrong'),3),'https://shop.test/catalog?sort=asc&page=3');
  assert.equal(scraper.pageUrl(profile('query_custom','paged'),2),'https://shop.test/catalog?sort=asc&paged=2');
  assert.equal(scraper.pageUrl(profile('query_custom',''),4),'https://shop.test/catalog?sort=asc&paged=4');
  assert.equal(scraper.pageUrl(profile('path_pattern','/p/{page}/','https://shop.test/catalog/page/7/?sort=asc#items'),5),'https://shop.test/catalog/p/5/');
  assert.equal(scraper.pageUrl(profile('full_pattern','','https://shop.test/catalog'),2),'');
  assert.equal(scraper.pageUrl(profile('full_pattern','https://cdn.test/{page}/x/{page}','https://shop.test/catalog'),8),'https://cdn.test/8/x/8');
  assert.equal(scraper.pageUrl(profile('next_selector','.next'),2),'https://shop.test/catalog?sort=asc#items');
});

test('response decoding honors declared legacy encodings and BOMs',()=>{
  const windows1252=Uint8Array.from([0x63,0x61,0x66,0xe9]);assert.equal(network.decodeResponseBody(windows1252,'text/html; charset=windows-1252'),'café');
  const utf16=Uint8Array.from([0xff,0xfe,0x33,0x06,0x44,0x06,0x27,0x06,0x45,0x06]);assert.equal(network.decodeResponseBody(utf16,'text/html'),'سلام');
});

test('hamburger menu preserves PHP order, count, dimensions and moves CSV transfer into products',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8'),expected=['💾 ذخیره و بازیابی همهٔ تنظیمات','📜 گزارش تغییرات کد','🔄 نسخهٔ کد','🛒 ووکامرس','🏪 باسلام','🤖 هوش مصنوعی','🔔 اعلان‌ها','🗂 محصولات رفته از مبدأ','⚙️ تنظیمات عمومی','🩺 نگهبان صف ارسال','🌐 اتصال به سایت مبدأ','🔍 مغایرت‌گیری با مقصد','🧠 یادگیری دسته‌بندی','✏️ مدیریت جامع محصولات مقصد','🖼 عکس‌دار کردن محصولات ووکامرس','🤖 پاسخ خودکار به مشتریان','🌙 گزارش شبانهٔ محصولات','📊 آمار محصولات هر پروفایل'];
  const block=source.slice(source.indexOf('const menuDefs='),source.indexOf('];',source.indexOf('const menuDefs='))),titles=[...block.matchAll(/^ \['([^']+)'/gm)].map(match=>match[1]);assert.deepEqual(titles,expected);assert.doesNotMatch(block,/product-transfer|درون‌ریزی و برون‌ریزی محصولات/);
  assert.match(source,/\.hamburger\{[^}]*width:44px;height:44px/);assert.match(source,/\.drawer\{[^}]*width:400px;max-width:90vw/);assert.match(source,/id="pane-products"[\s\S]*id="transferProfile"/);
});

test('nontechnical settings UI uses visual editors and comprehensive clickable test-result modals',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8');
  assert.doesNotMatch(source,/id="(?:importJson|aiImportBox)"/);assert.match(source,/id="profileImportFile"/);assert.match(source,/id="basalamShopsList"/);assert.match(source,/id="aiProvidersList"/);assert.match(source,/data-ai-row/);assert.match(source,/data-ai-sort/);assert.match(source,/data-ai-filter/);assert.match(source,/نتیجهٔ دسته‌بندی/);assert.match(source,/categoryTitle/);assert.match(source,/پاسخ خام دسته‌بندی/);assert.match(source,/data-ai-retry="message"/);assert.match(source,/data-ai-retry="category"/);assert.match(source,/function retryAiPart/);assert.match(source,/aiSkipTimeoutMs/);assert.match(source,/ai.skipTimeoutMs/);assert.match(source,/پاسخ خام مدل/);assert.match(source,/استعلام جامع ووکامرس/);assert.match(source,/نتیجهٔ جامع تست پاسخ خودکار/);assert.match(source,/نتیجهٔ بررسی نگهبان صف/);assert.match(source,/api\/ai\/test-results/);assert.match(source,/category-export'[\s\S]*api\('\/api\/category-learning\?limit=5000'/);assert.match(source,/if\(full\)await connect\(\)/);assert.match(source,/id="pane-destination"/);assert.match(source,/data-tab="destination"/);assert.match(source,/id="destBulkPreview"/);assert.match(source,/id="destBulkCategory"/);assert.match(source,/data-dest-action=[^\n]{0,20}category/);assert.match(source,/openDestinationCategoryManager/);assert.match(source,/روش دستی/);assert.match(source,/روش نیمه‌هوشمند/);assert.match(source,/روش هوشمند با مدل‌های انتخابی/);assert.match(source,/categoryAssignments/);assert.match(source,/data-category-row/);assert.match(source,/اصلاح دستی پیشنهاد این محصول/);assert.match(source,/source:'اصلاح دستی'/);assert.match(source,/id="destBulkDeletePreview"/);assert.match(source,/runDestinationBulk\(false,true\)/);assert.match(source,/api\/destination\/'\+dest\.target\+'\/bulk/);assert.match(source,/extraction-diagnostic/);assert.match(source,/No route for that URI|رفع مسیر Cloudflare Workers AI/);assert.match(source,/گزارش تغییرات کد/);
});

test('AI provider export is confidential and the Mistral catalog covers every official Text-to-text endpoint',async()=>{
  const dashboard=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8'),catalogSource=await readFile(new URL('../worker-src/ai-catalog.ts',import.meta.url),'utf8'),vault=await readFile(new URL('../worker-src/vault.ts',import.meta.url),'utf8');
  assert.match(dashboard,/برون‌ریزی محرمانه/);assert.match(dashboard,/فایل برون‌ریزی محرمانه است و کلیدهای API/);assert.match(dashboard,/ai-export-file'[\s\S]*exportAiProviders\(\)/);assert.match(dashboard,/format:'scraper4-ai-providers'/);assert.match(dashboard,/containsSecrets:true/);assert.match(dashboard,/providers:Array\.isArray\(ai\.providers\)/);assert.match(dashboard,/candidates:Array\.isArray\(ai\.candidates\)/);assert.match(dashboard,/master:String\(ai\.master/);assert.match(dashboard,/network:ai\.network/);assert.match(dashboard,/raw\?\.ai[\s\S]*config\.candidates[\s\S]*config\.network/);assert.match(dashboard,/mistral\.ai\/pricing\/api\//);
  assert.deepEqual([...catalog.MISTRAL_TEXT_TO_TEXT_MODELS],[
    'mistral-medium-latest','mistral-small-latest','mistral-large-latest','zai-glm-5-2','mistral-ocr-latest','voxtral-small-latest','codestral-latest','ministral-3b-latest','ministral-8b-latest','ministral-14b-latest','mistral-embed'
  ]);
  assert.deepEqual(catalog.MISTRAL_MODEL_ENDPOINTS,{'mistral-ocr-latest':'ocr','mistral-embed':'embeddings'});assert.doesNotMatch(JSON.stringify(catalog.MISTRAL_TEXT_TO_TEXT_MODELS),/labs-leanstral-2603/);
  for(const incompatible of ['voxtral-mini-latest','voxtral-mini-transcribe-realtime-2602','voxtral-mini-tts-latest','mistral-moderation-2603','codestral-embed'])assert.doesNotMatch(JSON.stringify(catalog.MISTRAL_TEXT_TO_TEXT_MODELS),new RegExp(incompatible),`${incompatible} lacks the official Text-to-text capability`);
  const ai={catalogVersion:0,providers:[{id:'mistral',name:'نام کاربر',baseUrl:'https://custom.example/mistral/v1',apiKey:'keep-this-key',models:['private-model'],enabled:true}]};assert.equal(catalog.upgradeAiProviderCatalog(ai),true);assert.equal(ai.catalogVersion,2);assert.equal(ai.providers.length,1);assert.equal(ai.providers[0].apiKey,'keep-this-key');assert.equal(ai.providers[0].baseUrl,'https://custom.example/mistral/v1');assert.equal(ai.providers[0].enabled,true);assert.ok(ai.providers[0].models.includes('private-model'));assert.ok(ai.providers[0].models.includes('mistral-ocr-latest'));assert.ok(ai.providers[0].models.includes('mistral-embed'));ai.providers[0].models=ai.providers[0].models.filter(model=>model!=='mistral-small-latest');assert.equal(catalog.upgradeAiProviderCatalog(ai),false);assert.equal(ai.providers[0].models.includes('mistral-small-latest'),false,'a user deletion remains stable after the one-time upgrade');
  assert.match(vault,/catalogVersion:num\(input\?\.ai\?\.catalogVersion/);assert.match(vault,/upgradeAiProviderCatalog\(result\.ai\)/);assert.match(dashboard,/OCR و Embedding با endpoint تخصصی/);assert.match(dashboard,/filter\(aiChatCompatibleModel\)/);
});

test('destination modals, green AI filtering, live progress, and clickable queue event details are fully wired',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8'),processor=await readFile(new URL('../worker-src/processor.ts',import.meta.url),'utf8'),types=await readFile(new URL('../worker-src/types.ts',import.meta.url),'utf8'),networkSource=await readFile(new URL('../worker-src/network.ts',import.meta.url),'utf8');
  for(const token of ['id="destinationManagerModal"','data-open-destination="woo"','data-open-destination="basalam"','data-open-destination="basalam-unapproved"','id="destSelectAll"','data-dest-view="cards"','data-dest-view="rows"','id="destUnapprovedTools"','id="destPerPage"'])assert.match(source,new RegExp(token));
  assert.match(source,/function openDestinationManager\(/);assert.match(source,/function closeDestinationManager\(/);assert.match(source,/function setDestinationView\(/);assert.match(source,/function selectDestinationPage\(/);assert.match(source,/destinationManagerModal'[\s\S]*closeDestinationManager/);
  for(const id of ['aiProgressBar','aiProgressTotal','aiProgressDone','aiProgressRemain','aiProgressResult','aiProgressCategory','aiProgressPercent','aiProgressElapsed','aiProgressCurrent'])assert.match(source,new RegExp(`id="${id}"`));
  assert.doesNotMatch(source,/aiMenuResult/);assert.match(source,/function updateAiTestProgress\(/);assert.match(source,/\.filter\(row=>row\.ok===true&&row\.chatCompatible!==false&&!aiDedicatedEndpoint\(row\.model\)\)/);assert.match(source,/در آخرین اجرای تست مدل‌ها هیچ مدل سبزی ثبت نشده است/);
  for(const event of ['added','updated','failed','removed','out-of-stock','zero-price','price-increased','price-decreased','sync-created','sync-updated'])assert.match(processor+types,new RegExp(`'${event}'`),`${event} event must be recorded`);
  assert.match(source,/data-job-metric/);assert.match(source,/function openJobMetric\(/);assert.match(source,/price-up/);assert.match(source,/price-down/);assert.match(source,/oldPrice/);assert.match(source,/percent/);
  assert.match(networkSource,/WOO_EDGE_ERRORS=new Set\(\[520,521,522,523,524,525,526\]\)/);assert.match(networkSource,/function safeWooFetch/);assert.match(source,/woo\.network\.mode/);assert.match(source,/woo\.network\.workerUrl/);
});

test('font picker, resilient AI result columns, Basalam chat modal, and real destination table are fully wired',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8'),appSource=await readFile(new URL('../worker-src/app.ts',import.meta.url),'utf8');
  assert.match(source,/فونت کل سایت/);for(const font of ['vazir','yekan','shabnam','sahel','samim'])assert.match(source,new RegExp(`${font}:\\{family:`));
  assert.match(source,/appearance\.font/);assert.match(source,/function applySiteFont\(/);assert.match(source,/appFontStylesheet/);assert.match(source,/\/assets\/fonts\/vazir\.css/);assert.match(appSource,/\/assets\/fonts\/:file/);assert.match(appSource,/\.css\$\/i/);assert.match(appSource,/\.woff2\$\/i/);assert.doesNotMatch(appSource,/v1\.fontapi\.ir|cdn\.fontcdn\.ir/);
  for(const id of ['aiProgressRetries','aiProgressSkipped'])assert.match(source,new RegExp(`id="${id}"`));assert.doesNotMatch(source,/AI_TEST_RESUME_KEY/);assert.match(source,/api\('\/api\/ai\/test-runs'/);assert.match(source,/api\('\/api\/ai\/test-runs\/current'/);assert.match(source,/controlAiModelTests/);assert.match(source,/serverSide:true/);assert.match(source,/messageSucceeded/);assert.match(source,/پاسخ پیام «/);assert.match(source,/خطای پاسخ پیام/);
  assert.match(source,/function openBasalamChats\(/);assert.match(source,/function openBasalamChatDetail\(/);assert.match(source,/api\/basalam\/chats\?limit=50/);assert.match(source,/\/messages\?limit=50/);assert.match(source,/خوانده‌نشده/);assert.match(source,/data-chat-search/);
  assert.match(source,/function destinationTable\(/);assert.match(source,/<table class="dest-table">/);assert.match(source,/else if\(dest\.view==='rows'\)box\.innerHTML=destinationTable\(\)/);assert.match(source,/else box\.innerHTML=dest\.products\.map/,'card mode remains an independent renderer');
});

test('durable background runners cover AI refresh recovery and every unapproved Basalam page',async()=>{
  const dashboard=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8'),background=await readFile(new URL('../worker-src/background.ts',import.meta.url),'utf8'),app=await readFile(new URL('../worker-src/app.ts',import.meta.url),'utf8'),main=await readFile(new URL('../worker-src/main.ts',import.meta.url),'utf8'),types=await readFile(new URL('../worker-src/types.ts',import.meta.url),'utf8');
  for(const endpoint of ['/api/ai/test-runs','/api/ai/test-runs/current','/api/ai/test-runs/control','/api/ai/test-runs/retry','/api/destination/basalam/category-runs','/api/destination/basalam/category-runs/current','/api/destination/basalam/category-runs/control'])assert.match(app,new RegExp(endpoint.replaceAll('/','\\/')));
  assert.match(background,/type BackgroundRun=/);assert.match(background,/type RunStatus='queued'\|'running'\|'paused'\|'done'\|'failed'/);assert.match(background,/testModelBatch/);assert.match(background,/destinationCatalog\('basalam',[\s\S]*page:run\.page/);assert.match(background,/status:'3567'/);assert.match(background,/applyBasalamCategory/);assert.match(background,/recoverBackgroundRuns/);assert.match(background,/retryJobs/);assert.match(dashboard,/initAutoSave/);assert.match(dashboard,/scheduleAutoSave/);assert.match(background,/STALL_MS=45_000/);assert.match(background,/watchdog-skip/);assert.match(background,/skipNext/);assert.match(background,/DEFAULT_SKIP_TIMEOUT_MS=1_000/);assert.match(background,/function aiSkipTimeoutMs/);
  assert.match(background,/INSERT INTO app_state[\s\S]*ON CONFLICT\(key\) DO UPDATE[\s\S]*WHERE app_state\.updated_at<\?/,'D1 compare-and-set lease prevents duplicate queue deliveries');assert.match(background,/DELETE FROM app_state WHERE key=\? AND value=\?/,'only the lease owner can release it');
  assert.match(types,/task:\s*'ai-test'\s*\|\s*'category-all'/);assert.match(main,/processBackgroundMessage/);assert.match(main,/result\.outcome==='continue'/);assert.match(app,/recoverBackgroundRuns\(waitUntil\)/);
  assert.match(dashboard,/startCategoryAllRun/);assert.match(dashboard,/دسته‌بندی همهٔ تأییدنشده‌ها/);assert.match(dashboard,/refreshCurrentCategoryRun\(true\)/);assert.match(dashboard,/refreshCurrentAiRun\(true\)/);assert.doesNotMatch(dashboard,/destUnapprovedSelect[^\n]*selectDestinationPage\(true\)/,'all-unapproved action must not be limited to the visible page');
});

test('dashboard startup cannot be stopped by a stale file input and settings restore is visibly wired',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8');
  assert.doesNotMatch(source,/\$\('restoreFile'\)/,'removed restoreFile input must not be bound');
  assert.doesNotMatch(source,/\$\('[^']+'\)\.addEventListener/,'literal event bindings must tolerate an optional/moved UI control');
  assert.match(source,/\['sxFile','bkFile'\][\s\S]*restoreSettingsFile\(file\)/);
  assert.match(source,/api\('\/api\/settings-import'/);
  assert.match(source,/بازیابی کامل شد/);
});

test('dashboard remembers and restores the last active profile after refresh',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8');
  assert.match(source,/LAST_PROFILE_KEY='scraper4:last-profile-id'/);
  assert.match(source,/function rememberProfile\(id\)[\s\S]*localStorage\.setItem\(LAST_PROFILE_KEY,value\)/);
  assert.match(source,/const lastProfileId=rememberedProfileId\(\)\|\|state\.selected[\s\S]*editProfile\(lastProfileId,false\)[\s\S]*forgetRememberedProfile\(lastProfileId\);clearForm\(\)/);
  assert.match(source,/function editProfile\(id,navigate=true\)[\s\S]*rememberProfile\(id\)/);
  assert.match(source,/function activateProfile\(id\)[\s\S]*editProfile\(value,false\)[\s\S]*syncProfileSelects\(value\)/);
  assert.match(source,/\['productProfile','transferProfile','photoProfile','sendProfile','importProfile'\][\s\S]*activateProfile\(event\.target\.value\)/);
  assert.match(source,/rememberProfile\(result\.profile\.id\)[\s\S]*syncProfileSelects\(result\.profile\.id\)/);
  assert.match(source,/forgetRememberedProfile\(id\)[\s\S]*await refreshProfiles\(\)/);
});

test('drawer controls stay above the menu, open section heads are sticky, and general settings expose twelve live themes plus reasoning flags',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8'),vault=await readFile(new URL('../worker-src/vault.ts',import.meta.url),'utf8'),ai=await readFile(new URL('../worker-src/ai.ts',import.meta.url),'utf8');
  assert.match(source,/<div class="drawer-head">[\s\S]*id="drawerFull"[\s\S]*id="drawerClose"[\s\S]*<div id="menuSections"/,'full-width control is rendered inside the sticky drawer header');
  assert.match(source,/\.drawer-head \.fullwidth-btn\{position:static/);assert.match(source,/\.menu-section\.open>\.menu-title\{position:sticky;top:70px/);assert.match(source,/\.menu-section\.open \.menu-content\{max-height:none;[^}]*overflow:visible/,'long menu sections use drawer scrolling instead of a competing internal scroller');
  assert.match(source,/mSelect\('رنگ‌بندی کل سایت:','siteTheme'/);assert.match(source,/id="themeSwatches"/);assert.match(source,/data-theme-choice/);assert.match(source,/applySiteTheme\(nestedGet\(state\.settings,'appearance\.theme'\)/);
  const themeBlock=source.slice(source.indexOf('const SITE_THEMES='),source.indexOf('const SITE_FONTS=')),themeKeys=[...themeBlock.matchAll(/(?:\{|,)([a-z]+):\[/g)].map(match=>match[1]);assert.equal(themeKeys.length,12);assert.deepEqual(themeKeys,['midnight','ocean','aurora','royal','sunset','rose','cobalt','forest','graphite','coffee','persian','cyber']);
  assert.match(source,/data-ai-reasoning-index/);assert.match(source,/aiEditorReasoning/);assert.match(source,/reasoningModels:models\.filter/);assert.match(source,/مدل رایگان Together/);assert.match(vault,/reasoningModels:string\[\]/);assert.match(vault,/reasoningModels:Array\.isArray/);assert.match(ai,/isReasoningAiModel/);assert.match(ai,/max_tokens:reasoning\?1600:400/);assert.match(ai,/preferredAiChatModel/);
});

test('mobile RTL redesign keeps the requested bottom navigation order and touch-friendly theme',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8'),start=source.indexOf('<nav class="main-tabs" aria-label="ناوبری اصلی">'),end=source.indexOf('</nav>',start),nav=source.slice(start,end);
  assert.ok(start>0&&end>start,'main navigation must exist');
  assert.deepEqual([...nav.matchAll(/data-tab="([^"]+)"/g)].map(match=>match[1]),['home','settings','selector','products','destination','jobs']);
  assert.deepEqual([...nav.matchAll(/<span>([^<]+)<\/span>/g)].map(match=>match[1]),['شروع','تنظیمات','سلکتورها','نتایج','ارسال','درون‌ریزی']);
  assert.equal((nav.match(/<svg /g)||[]).length,6);assert.match(nav,/id="productBadge"/);assert.match(nav,/id="jobBadge"/);
  assert.match(source,/--bg:#050a13;--card:#121d30/);assert.match(source,/\.main-tabs\{top:auto!important;bottom:0!important/);assert.match(source,/env\(safe-area-inset-bottom\)/);assert.match(source,/\.hamburger,\.fullwidth-btn\{top:12px;width:52px;height:52px/);assert.match(source,/input,select,textarea\{min-height:50px/);assert.match(source,/\$\('productBadge'\)\.hidden=!data\.total/);
});

test('workflow panes match the reference hierarchy and every new control is operationally wired',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8');
  for(const id of ['releaseBanner','homeProfile','homeAutoMode','homeManualMode','homeScrape','homeDiagnose','homeBackend','homeJobs','settingsProfile','savePriceSettings','sendProfile','quickWoo','quickBasalam','destinationJobs','importProfile','importStatus','importFile','importAnalyze','importResult'])assert.equal((source.match(new RegExp(`id="${id}"`,'g'))||[]).length,1,`${id} must be unique`);
  for(const id of ['titleSuffix','priceMode','priceValue','roundPrice','minPrice','wooCategoryId','basalamCategoryId','basalamFallbackCategoryIds','enabled','networkIndirect','noExtract','syncWoo','syncBasalam'])assert.equal((source.match(new RegExp(`id="${id}"`,'g'))||[]).length,1,`${id} moved to settings without duplication`);
  const settings=source.slice(source.indexOf('<section id="pane-settings"'),source.indexOf('<nav class="main-tabs"'));assert.match(settings,/مدیریت قیمت/);assert.match(settings,/دسته‌بندی جداگانه برای هر مقصد/);assert.match(settings,/settings-help/);
  const destination=source.slice(source.indexOf('<section id="pane-destination"'),source.indexOf('<section id="pane-jobs"'));assert.match(destination,/ارسال سریع محصولات/);assert.match(destination,/مدیریت جامع مقصد/);
  const jobs=source.slice(source.indexOf('<section id="pane-jobs"'),source.indexOf('<section id="pane-settings"'));assert.match(jobs,/آپلود فایل CSV یا Excel/);assert.match(jobs,/file-picker/);
  assert.match(source,/createJob\(\$\('sendProfile'\)\.value,'sync','woo',false\)/);assert.match(source,/createJob\(\$\('sendProfile'\)\.value,'sync','basalam',false\)/);assert.match(source,/importCsv\(\$\('importFile'\)\.files\[0\]/);assert.match(source,/saveProfile\(false,true\)/);
  const appSource=await readFile(new URL('../worker-src/app.ts',import.meta.url),'utf8');assert.match(appSource,/read-excel-file\/web-worker/);assert.match(appSource,/destinationStatus:wooStatus\|\|undefined/);
});

test('remaining dashboard content follows a topic-first novice workflow without dropping advanced tools',async()=>{
  const source=await readFile(new URL('../worker-src/dashboard.ts',import.meta.url),'utf8');
  const home=source.slice(source.indexOf('<section id="pane-home"'),source.indexOf('<section id="pane-selector"'));
  assert.ok(home.indexOf('پروفایل‌های ذخیره‌شده')<home.indexOf('آدرس و محدودهٔ استخراج'));assert.ok(home.indexOf('آدرس و محدودهٔ استخراج')<home.indexOf('شروع استخراج محصولات'));assert.ok(home.indexOf('شروع استخراج محصولات')<home.indexOf('نمای کلی و فهرست همهٔ پروفایل‌ها'));
  assert.match(home,/<details class="support-panel profile-library">[\s\S]*id="profileList"/);for(const id of ['homeProfileName','homeSaveProfile','homeDeleteProfile','homeSyncEnabled','homeSyncInterval','homeSyncTarget','homeNoExtract','homeSyncWoo','homeSyncBasalam','homeNetworkIndirect','homeUrl','homePages','homePagination','homePaginationValue','homeReset','homeClearJobs','homeRefreshJobs'])assert.match(home,new RegExp(`id="${id}"`));
  const selector=source.slice(source.indexOf('<section id="pane-selector"'),source.indexOf('<section id="pane-products"'));
  assert.deepEqual([...selector.matchAll(/<span class="step-badge">([^<]+)<\/span>/g)].map(x=>x[1]),['۱','۲','۳']);
  assert.ok(selector.indexOf('منبع و صفحه‌بندی')<selector.indexOf('فیلدهای فهرست محصولات')&&selector.indexOf('فیلدهای فهرست محصولات')<selector.indexOf('جزئیات صفحهٔ محصول'));
  const products=source.slice(source.indexOf('<section id="pane-products"'),source.indexOf('<section id="pane-destination"'));
  assert.ok(products.indexOf('محصولات استخراج‌شده')<products.indexOf('ابزارهای خروجی و انتقال'));
  assert.match(products,/id="goImportTab"[\s\S]*ورود CSV \/ Excel جدید/);assert.match(source,/\$\('goImportTab'\)[\s\S]*tab\('jobs'\)/);
  const settings=source.slice(source.indexOf('<section id="pane-settings"'),source.indexOf('<nav class="main-tabs"'));
  assert.ok(settings.indexOf('مدیریت قیمت')<settings.indexOf('ابزارهای فنی و مهاجرت'));assert.match(settings,/<details class="support-panel technical-panel">/);
  assert.deepEqual(Object.values({maintenance:'🧰 نگهداری و نسخه',connections:'🔌 اتصال‌ها و سرویس‌ها',operations:'📦 عملیات محصولات و سلامت',automation:'🤖 اتوماسیون و گزارش'}).filter(label=>source.includes(label)).length,4);
  assert.match(source,/menuDefs\.map\(\(\[title,key,desc,content\],index\)/);
});

test('processor refuses unsafe retirement after empty, duplicate or failed extraction and preserves detail tags',async()=>{
  const source=await readFile(new URL('../worker-src/processor.ts',import.meta.url),'utf8');assert.match(source,/checkpoint\.retireSafe=false;[\s\S]*صفحه.*خالی/);assert.match(source,/فقط محصولات تکراری/);assert.match(source,/if\(checkpoint\.retireSafe&&checkpoint\.seen\.length\)/);assert.match(source,/هیچ محصولی بازنشسته نشد/);assert.match(source,/tags:fresh\.tags\|\|previous\.tags/);
});
