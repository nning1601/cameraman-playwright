// โหลด readline-sync สำหรับรับ input
const readline = require('readline-sync');


// ฟังก์ชันหลัก รับ callback

function calculate(num1, num2, operation) {
    return operation(num1, num2);
}

// คำนวน

const add = function(a, b) { return a + b; };


const subtract = (a, b) => a - b;

const multiply = function(a, b) { return a * b; };


const divide = (a, b) =>  a / b;

//รับ
let num1 = parseFloat(readline.question(" 1: "));
let num2 = parseFloat(readline.question(" 2: "));
//แสดง
console.log("บวก: " + calculate(num1, num2, add));
console.log("ลบ: " + calculate(num1, num2, subtract));
console.log("คูณ: " + calculate(num1, num2, multiply));
console.log("หาร: " + calculate(num1, num2, divide));
