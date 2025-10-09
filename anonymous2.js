function inc(num, func) {
  num += 1;       // เพิ่มค่า num
  func(num);      // ส่งค่า num ไปยัง callback function
}

// เรียกใช้
inc(99, function(v) {
  console.log(v); // จะได้ผลลัพธ์ 100
});
