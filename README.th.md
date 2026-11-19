# Friday Shop (ตัวอย่างโค้ดบางส่วน)

[English](README.md) · เว็บจริง: [shop.friday-pet.com](https://shop.friday-pet.com)

เว็บขายของสัตว์เลี้ยงขนาดเล็กที่ผมทำใช้จริง พอดีรีโปหลักปิด Private ไว้เพราะเป็นร้านที่รันขายของอยู่ เลยคัดบางไฟล์ที่ไม่ติดข้อมูลร้านมาใส่ไว้ในนี้แทน เผื่อดูแนวทางการเขียนโค้ด (ถ้าอยากดูตัวเต็มคุยตอนสัมภาษณ์ได้ครับ)

## ตัวอย่างไฟล์ใน `samples/`

- [`multi-sqlite-setup.php`](samples/multi-sqlite-setup.php) - แยกไฟล์ SQLite ตามหมวดงาน + เปิด WAL mode ลดปัญหา database locked เวลาคนเข้าเยอะๆ แล้วค่อย `ATTACH` ข้ามไฟล์เอาตอนต้อง join
- [`rate-limit.php`](samples/rate-limit.php) - ตัวกันยิงรัวๆ แยก 2 แบบ: หน้า login เก็บลง SQLite ให้จำค่าข้าม worker ได้ ส่วนพวกสถิติ/แชทใช้ APCu หรือไฟล์ + `flock()` แทน จะได้ไม่ไปล็อก DB หลัก
- [`cloudflare-worker.js`](samples/cloudflare-worker.js) - ดัก `Accept: text/markdown` ที่ Cloudflare แล้วส่งหน้า Markdown จาก origin กลับไปให้พวก AI bot
- [`webmcp-tools-excerpt.js`](samples/webmcp-tools-excerpt.js) - ตัวอย่างลงทะเบียน WebMCP tool บนเบราว์เซอร์ ส่ง payload ใน POST body แทน query string กับคืน error เป็น JSON
- [`build-pipeline-excerpt.js`](samples/build-pipeline-excerpt.js) - ตัวช่วยแบ่ง bundle (หน้าลูกค้า / หน้าโหลดทีหลัง / แอดมิน) แล้วเช็ค MD5 เนื้อหาไฟล์ก่อน build จะได้ไม่ต้อง build ใหม่ทุกครั้ง

## Stack หลัก

PHP 8.3, SQLite (WAL), React, TanStack Query, Tailwind, esbuild, Workbox, Cloudflare Workers
